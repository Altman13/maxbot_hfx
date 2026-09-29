<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\StateFileHandler;
use App\Application\State\State;
use PHPUnit\Framework\TestCase;

class StateFileHandlerTest extends TestCase
{
    private StateFileHandler $handler;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/state_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->handler = new StateFileHandler();

        // Подменяем stateDir на временный каталог через Reflection
        $ref = new \ReflectionClass($this->handler);
        $prop = $ref->getProperty('stateDir');
        $prop->setAccessible(true);
        $prop->setValue($this->handler, $this->tempDir);
    }

    protected function tearDown(): void
    {
        // Чистим временный каталог
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    // ============================================================
    // getStateDirectory / getStateFilePath
    // ============================================================

    public function testGetStateDirectoryReturnsTempDir(): void
    {
        $this->assertSame($this->tempDir, $this->handler->getStateDirectory());
    }

    public function testGetStateFromFileReturnsEmptyArrayWhenFileNotFound(): void
    {
        $result = $this->handler->getStateFromFile('999');

        $this->assertSame([], $result);
    }

    // ============================================================
    // saveStateToFile / getStateFromFile
    // ============================================================

    public function testSaveStateToFileAndGetBack(): void
    {
        $data = ['state' => State::Main_Menu->value, 'contactId' => 123];

        $this->handler->saveStateToFile('123', $data);
        $result = $this->handler->getStateFromFile('123');

        $this->assertSame(State::Main_Menu->value, $result['state']);
        $this->assertSame(123, $result['contactId']);
    }

    public function testSaveStateToFileMergesWithExistingData(): void
    {
        $this->handler->saveStateToFile('123', ['state' => State::Main_Menu->value]);
        $this->handler->saveStateToFile('123', ['contactId' => 456]);

        $result = $this->handler->getStateFromFile('123');

        $this->assertSame(State::Main_Menu->value, $result['state']);
        $this->assertSame(456, $result['contactId']);
    }

    // ============================================================
    // setStateField / getStateField
    // ============================================================

    public function testSetStateFieldAndGetBack(): void
    {
        $this->handler->setStateField('123', 'issueId', '789');

        $this->assertSame('789', $this->handler->getStateField('123', 'issueId'));
    }

    public function testGetStateFieldReturnsEmptyStringForMissingField(): void
    {
        $this->handler->saveStateToFile('123', ['state' => State::Main_Menu->value]);

        $result = $this->handler->getStateField('123', 'nonexistent');

        $this->assertSame('', $result);
    }

    public function testGetStateFieldReturnsEmptyStringForMissingChat(): void
    {
        $result = $this->handler->getStateField('999', 'state');

        $this->assertSame('', $result);
    }

    public function testSetStateFieldOverridesExistingValue(): void
    {
        $this->handler->setStateField('123', 'issueId', '111');
        $this->handler->setStateField('123', 'issueId', '222');

        $this->assertSame('222', $this->handler->getStateField('123', 'issueId'));
    }

    // ============================================================
    // getStateFields
    // ============================================================

    public function testGetStateFieldsReturnsThreeValues(): void
    {
        $this->handler->setStateField('123', 'companyId', '100');
        $this->handler->setStateField('123', 'contactId', '200');
        $this->handler->setStateField('123', 'issueId', '300');

        $result = $this->handler->getStateFields('123');

        $this->assertSame(['100', '200', '300'], $result);
    }

    public function testGetStateFieldsReturnsEmptyStringsForMissingFields(): void
    {
        $result = $this->handler->getStateFields('999');

        $this->assertSame(['', '', ''], $result);
    }

    // ============================================================
    // clearStateKey
    // ============================================================

    public function testClearStateKeyRemovesField(): void
    {
        $this->handler->setStateField('123', 'issueId', '789');
        $this->handler->setStateField('123', 'state', State::Main_Menu->value);

        $this->handler->clearStateKey('123', 'issueId');

        $this->assertSame('', $this->handler->getStateField('123', 'issueId'));
        $this->assertSame(State::Main_Menu->value, $this->handler->getStateField('123', 'state'));
    }

    public function testClearStateKeyDoesNothingForMissingKey(): void
    {
        $this->handler->setStateField('123', 'state', State::Main_Menu->value);

        // Не должно упасть
        $this->handler->clearStateKey('123', 'nonexistent');

        $this->assertSame(State::Main_Menu->value, $this->handler->getStateField('123', 'state'));
    }

    // ============================================================
    // clearStateKeys (множественная очистка)
    // ============================================================

    public function testClearStateKeysRemovesMultipleFields(): void
    {
        $this->handler->setStateField('123', 'inventory_number', 'INV1');
        $this->handler->setStateField('123', 'maintenance_entity_id', 'M1');
        $this->handler->setStateField('123', 'attachmentFileName', 'file.pdf');
        $this->handler->setStateField('123', 'state', State::Main_Menu->value);

        $this->handler->clearStateKeys('123');

        $this->assertSame('', $this->handler->getStateField('123', 'inventory_number'));
        $this->assertSame('', $this->handler->getStateField('123', 'maintenance_entity_id'));
        $this->assertSame('', $this->handler->getStateField('123', 'attachmentFileName'));
        // state НЕ должен быть удалён
        $this->assertSame(State::Main_Menu->value, $this->handler->getStateField('123', 'state'));
    }

    // ============================================================
    // clearStateKeyIssueId
    // ============================================================

    public function testClearStateKeyIssueIdRemovesOnlyIssueId(): void
    {
        $this->handler->setStateField('123', 'issueId', '789');
        $this->handler->setStateField('123', 'state', State::Main_Menu->value);

        $this->handler->clearStateKeyIssueId('123');

        $this->assertSame('', $this->handler->getStateField('123', 'issueId'));
        $this->assertSame(State::Main_Menu->value, $this->handler->getStateField('123', 'state'));
    }

    // ============================================================
    // clearState — сохраняет базовые поля
    // ============================================================

    public function testClearStateKeepsEssentialFieldsAndResetsState(): void
    {
        $this->handler->saveStateToFile('123', [
            'state' => State::Request_Created->value,
            'role' => 'employee',
            'companyId' => '100',
            'contactId' => '200',
            'phone_number' => '+79991234567',
            'issueId' => '789', // не должен сохраниться
        ]);

        $this->handler->clearState('123');

        $result = $this->handler->getStateFromFile('123');

        $this->assertSame(State::Main_Menu->value, $result['state']);
        $this->assertSame('employee', $result['role']);
        $this->assertSame('100', $result['companyId']);
        $this->assertSame('200', $result['contactId']);
        $this->assertSame('+79991234567', $result['phone_number']);
        $this->assertArrayNotHasKey('issueId', $result);
    }

    public function testClearStateDoesNothingWhenFileNotExists(): void
    {
        // Просто вызываем — не должно быть ошибок
        $this->handler->clearState('999');

        $this->assertSame([], $this->handler->getStateFromFile('999'));
    }

    // ============================================================
    // clearAllState
    // ============================================================

    public function testClearAllStateEmptiesFile(): void
    {
        $this->handler->setStateField('123', 'state', State::Main_Menu->value);
        $this->handler->setStateField('123', 'issueId', '789');

        $this->handler->clearAllState('123');

        $result = $this->handler->getStateFromFile('123');
        $this->assertSame([], $result);
    }

    public function testClearAllStateDoesNothingWhenFileNotExists(): void
    {
        $this->handler->clearAllState('999');

        $this->assertSame([], $this->handler->getStateFromFile('999'));
    }
}