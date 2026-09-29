<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\StateManagerHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StateManagerHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var StateFileHandler&MockInterface */
    private StateFileHandler $stateFileHandler;

    /** @var LoggerInterface&MockInterface */
    private LoggerInterface $logger;

    /** @var MenuCreateAction&MockInterface */
    private MenuCreateAction $menuCreateAction;

    /** @var MaxBotHelper&MockInterface */
    private MaxBotHelper $maxBotHelper;

    private StateManagerHandler $handler;

    protected function setUp(): void
    {
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->maxBotHelper = Mockery::mock(MaxBotHelper::class);

        $this->maxBotHelper->shouldReceive('sendMessage')->andReturn([])->byDefault();
        $this->maxBotHelper->shouldReceive('sendMenu')->andReturn([])->byDefault();

        $this->handler = new StateManagerHandler(
            $this->stateFileHandler,
            $this->logger,
            $this->menuCreateAction,
            $this->maxBotHelper
        );
    }

    // ============================================================
    // processState
    // ============================================================

    public function testProcessStateSendsMenuForKnownState(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Main Menu'];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogic')
            ->once()
            ->andReturn($menu);

        $this->maxBotHelper
            ->shouldReceive('sendMenu')
            ->once()
            ->with($menu);

        $this->handler->processState('123', State::Main_Menu);
    }


    public function testProcessStateFallsBackToSelectFromMenuForUnknownState(): void
    {
        // Для стейта, которого нет в карте getResponseMessage, вернётся Select_From_Menu
        $menu = ['chat_id' => '123', 'text' => 'Select'];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogic')
            ->once()
            ->andReturn($menu);

        // Любой стейт, не указанный в явной карте — например, State::Scan_Plotter_QR_Code
        $this->handler->processState('123', State::Scan_Plotter_QR_Code);
    }

    // ============================================================
    // getResponseMessage
    // ============================================================

    public function testGetResponseMessageReturnsTextForKnownState(): void
    {
        $result = $this->handler->getResponseMessage(State::Main_Menu->value);

        $this->assertSame(ResponseMessage::Main_Menu->value, $result);
    }

    public function testGetResponseMessageReturnsTextForStart(): void
    {
        $result = $this->handler->getResponseMessage(State::Start->value);

        $this->assertSame(ResponseMessage::Request_Contact_Share->value, $result);
    }

    public function testGetResponseMessageReturnsTextForFinishRequest(): void
    {
        $result = $this->handler->getResponseMessage(State::Finish_Request->value);

        $this->assertSame(ResponseMessage::Finish_Request->value, $result);
    }

    public function testGetResponseMessageReturnsTextForCutByQrCode(): void
    {
        $result = $this->handler->getResponseMessage(State::Cut_By_QR_Code->value);

        $this->assertSame(ResponseMessage::QR_Code->value, $result);
    }

    public function testGetResponseMessageReturnsTextForCreateRequest(): void
    {
        $result = $this->handler->getResponseMessage(State::Create_Request->value);

        $this->assertSame(ResponseMessage::Instruction_Notification->value, $result);
    }

    public function testGetResponseMessageReturnsSelectFromMenuForUnknownState(): void
    {
        $result = $this->handler->getResponseMessage('nonexistent_state');

        $this->assertSame(ResponseMessage::Select_From_Menu->value, $result);
    }

    public function testGetResponseMessageReturnsMainMenuForStay(): void
    {
        $result = $this->handler->getResponseMessage(State::Stay->value);

        $this->assertSame(ResponseMessage::Main_Menu->value, $result);
    }

    public function testGetResponseMessageReturnsMainMenuForReturnToStart(): void
    {
        $result = $this->handler->getResponseMessage(State::Return_To_Start->value);

        $this->assertSame(ResponseMessage::Main_Menu->value, $result);
    }

    // ============================================================
    // isValidStateTransition
    // ============================================================

    public function testIsValidStateTransitionReturnsTrueWhenStateInList(): void
    {
        $result = $this->handler->isValidStateTransition(
            State::Main_Menu->value,
            [State::Main_Menu->value, State::Create_Request->value]
        );

        $this->assertTrue($result);
    }

    public function testIsValidStateTransitionReturnsFalseWhenStateNotInList(): void
    {
        $result = $this->handler->isValidStateTransition(
            State::Finish_Request->value,
            [State::Main_Menu->value, State::Create_Request->value]
        );

        $this->assertFalse($result);
    }

    public function testIsValidStateTransitionReturnsFalseForEmptyList(): void
    {
        $result = $this->handler->isValidStateTransition(State::Main_Menu->value, []);

        $this->assertFalse($result);
    }

    // ============================================================
    // isValidStringData
    // ============================================================

    public function testIsValidStringDataReturnsTrueForString(): void
    {
        $this->assertTrue($this->handler->isValidStringData('hello'));
    }

    public function testIsValidStringDataReturnsTrueForEmptyString(): void
    {
        $this->assertTrue($this->handler->isValidStringData(''));
    }

    public function testIsValidStringDataReturnsFalseForArray(): void
    {
        $this->assertFalse($this->handler->isValidStringData(['a' => 1]));
    }

    public function testIsValidStringDataReturnsFalseForNull(): void
    {
        $this->assertFalse($this->handler->isValidStringData(null));
    }

    public function testIsValidStringDataReturnsFalseForInt(): void
    {
        $this->assertFalse($this->handler->isValidStringData(123));
    }

    // ============================================================
    // isValidArrayData
    // ============================================================

    public function testIsValidArrayDataReturnsTrueForNonEmptyArray(): void
    {
        $this->assertTrue($this->handler->isValidArrayData(['a' => 1]));
    }

    public function testIsValidArrayDataReturnsFalseForEmptyArray(): void
    {
        $this->assertFalse($this->handler->isValidArrayData([]));
    }

    public function testIsValidArrayDataReturnsFalseForString(): void
    {
        $this->assertFalse($this->handler->isValidArrayData('hello'));
    }

    public function testIsValidArrayDataReturnsFalseForNull(): void
    {
        $this->assertFalse($this->handler->isValidArrayData(null));
    }

    // ============================================================
    // processStateFromText
    // ============================================================

    public function testProcessStateFromTextProcessesKnownState(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Main'];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogic')
            ->once()
            ->andReturn($menu);

        $this->handler->processStateFromText('123', State::Main_Menu->value);
    }

    public function testProcessStateFromTextSendsMessageForUnknownText(): void
    {
        // Если текст не совпадает со стейтом — берём текущий стейт из файла
        // и отправляем ответ для него
        $this->stateFileHandler
            ->shouldReceive('getStateFromFile')
            ->with('123')
            ->andReturn(['state' => State::Main_Menu->value]);

        $this->maxBotHelper
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Main_Menu->value);

        $this->handler->processStateFromText('123', 'unknown text');
    }

    public function testProcessStateFromTextHandlesEmptyStateInFile(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateFromFile')
            ->with('123')
            ->andReturn([]);

        $this->maxBotHelper
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Select_From_Menu->value);

        $this->handler->processStateFromText('123', 'unknown');
    }
}