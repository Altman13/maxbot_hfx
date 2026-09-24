<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Menu\Keyboard;

use App\Application\Actions\Menu\Keyboard\ButtonsFactory;
use App\Application\State\TransitionMap;
use App\Application\State\State;
use PHPUnit\Framework\TestCase;

class ButtonsFactoryTest extends TestCase
{
    private ButtonsFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ButtonsFactory(new TransitionMap());
    }

    // ============================================================
    // Share_Contact — специальный случай с request_contact
    // ============================================================

    public function testCreateForStartReturnsContactButton(): void
    {
        $buttons = $this->factory->createFor(State::Start);

        $this->assertCount(1, $buttons);
        $this->assertSame(State::Share_Contact->value, $buttons[0]['text']);
        $this->assertTrue($buttons[0]['request_contact']);
        $this->assertArrayNotHasKey('callback_data', $buttons[0]);
    }
    // ============================================================
    // Main_Menu — обычные callback-кнопки
    // ============================================================

    public function testCreateForMainMenuReturnsCallbackButtons(): void
    {
        $buttons = $this->factory->createFor(State::Main_Menu);

        $this->assertNotEmpty($buttons);

        foreach ($buttons as $button) {
            $this->assertArrayHasKey('text', $button);
            $this->assertArrayHasKey('callback_data', $button);
            $this->assertSame($button['text'], $button['callback_data']);
        }
    }

    public function testCreateForMainMenuContainsCreateRequest(): void
    {
        $buttons = $this->factory->createFor(State::Main_Menu);

        $texts = array_column($buttons, 'text');

        $this->assertContains(State::Create_Request->value, $texts);
        $this->assertContains(State::Unlock_Cutter->value, $texts);
    }

    // ============================================================
    // Close_Command — Yes / No
    // ============================================================

    public function testCreateForCloseCommandReturnsYesNoButtons(): void
    {
        $buttons = $this->factory->createFor(State::Close_Command);

        $texts = array_column($buttons, 'text');

        $this->assertContains(State::Yes->value, $texts);
        $this->assertContains(State::No->value, $texts);
    }

    // ============================================================
    // Состояние без переходов — пустой массив
    // ============================================================

    public function testCreateForStateWithoutTransitionsReturnsEmptyArray(): void
    {
        $transitions = (new TransitionMap())->getStandardTransitions();

        $missing = null;
        foreach (State::cases() as $state) {
            if (!isset($transitions[$state->value]) && $state !== State::Share_Contact) {
                $missing = $state;
                break;
            }
        }

        if ($missing === null) {
            $this->markTestSkipped('Все стейты имеют переходы');
        }

        $buttons = $this->factory->createFor($missing);

        $this->assertSame([], $buttons);
    }

    // ============================================================
    // Формат каждой кнопки
    // ============================================================

    public function testEachCallbackButtonHasTextAndCallbackData(): void
    {
        $buttons = $this->factory->createFor(State::Create_Request);

        foreach ($buttons as $button) {
            $this->assertArrayHasKey('text', $button);
            $this->assertArrayHasKey('callback_data', $button);
            $this->assertNotEmpty($button['text']);
            $this->assertNotEmpty($button['callback_data']);
        }
    }
}
