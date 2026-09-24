<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Hook;

use App\Application\Actions\Hook\ListHooksAction;
use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\StateManagerHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\Helpers\QrCodeHelper;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ListHooksActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var LoggerInterface&MockInterface */
    private LoggerInterface $logger;

    /** @var ContainerInterface&MockInterface */
    private ContainerInterface $container;

    /** @var MaxBotHelper&MockInterface */
    private MaxBotHelper $maxBot;

    /** @var MenuCreateAction&MockInterface */
    private MenuCreateAction $menuCreateAction;

    /** @var StateFileHandler&MockInterface */
    private StateFileHandler $stateFileHandler;

    /** @var OkDeskHelper&MockInterface */
    private OkDeskHelper $okdeskHelper;

    /** @var StateManagerHandler&MockInterface */
    private StateManagerHandler $stateManagerHandler;

    /** @var QrCodeHelper&MockInterface */
    private QrCodeHelper $qrCodeHelper;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->container = Mockery::mock(ContainerInterface::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->okdeskHelper = Mockery::mock(OkDeskHelper::class);
        $this->stateManagerHandler = Mockery::mock(StateManagerHandler::class);
        $this->qrCodeHelper = Mockery::mock(QrCodeHelper::class);

        // Контейнер "знает" все ключи
        $this->container->shouldReceive('has')->andReturn(true);
    }

    // ============================================================
    // Конструктор
    // ============================================================

    public function testActionCanBeConstructed(): void
    {
        $this->assertInstanceOf(ListHooksAction::class, $this->makeAction());
    }

    // ============================================================
    // BotStarted → InitialRegistration (пустой стейт)
    // ============================================================

    public function testHandleMessageCallsBotStartedHandlerFirst(): void
    {
        $botStarted = Mockery::mock();
        $botStarted->shouldReceive('handle')->once()->with([], '123');

        $initialReg = Mockery::mock();
        $initialReg->shouldReceive('handle')->once()->with('', '123')->andReturn(true);

        $this->container->shouldReceive('get')->with('botStartedHandler')->andReturn($botStarted);
        $this->container->shouldReceive('get')->with('initialRegistrationHandler')->andReturn($initialReg);

        $this->stateFileHandler->shouldReceive('getStateFromFile')
            ->with('123')->andReturn(['state' => '']);
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'file_links')->andReturn([]);

        // commandHandler и registrationHandler должны быть вызваны
        $commandHandler = Mockery::mock();
        $commandHandler->shouldReceive('handleStartAndDelete')->once()->andReturn(false);
        $this->container->shouldReceive('get')->with('commandHandler')->andReturn($commandHandler);

        $registrationHandler = Mockery::mock();
        $registrationHandler->shouldReceive('handleContactMessage')->once()->andReturn(false);
        $this->container->shouldReceive('get')->with('registrationHandler')->andReturn($registrationHandler);

        $this->invokeHandleMessage($this->makeAction(), '', '123');
    }

    // ============================================================
    // CommandHandler — /start
    // ============================================================

    public function testHandleMessageStopsOnCommandHandler(): void
    {
        $this->mockBotStarted();

        $commandHandler = Mockery::mock();
        $commandHandler->shouldReceive('handleStartAndDelete')
            ->once()->with('/start', '123')->andReturn(true);
        $this->container->shouldReceive('get')->with('commandHandler')->andReturn($commandHandler);

        $this->mockState(State::Main_Menu->value);

        $this->invokeHandleMessage($this->makeAction(), '/start', '123');
    }

    // ============================================================
    // RegistrationHandler
    // ============================================================

    public function testHandleMessageStopsOnRegistrationHandler(): void
    {
        $this->mockBotStarted();
        $this->mockCommandHandlerWithStartAndDelete(false);

        $registrationHandler = Mockery::mock();
        $registrationHandler->shouldReceive('handleContactMessage')
            ->once()->with([], '123', '')->andReturn(true);
        $this->container->shouldReceive('get')->with('registrationHandler')->andReturn($registrationHandler);

        $this->mockState('');

        $this->invokeHandleMessage($this->makeAction(), '', '123');
    }

    // ============================================================
    // InitialRegistrationHandler
    // ============================================================

    public function testHandleMessageStopsOnInitialRegistration(): void
    {
        $this->mockBotStarted();
        $this->mockCommandHandlerWithStartAndDelete(false);
        $this->mockRegistrationHandler(false);

        $initialReg = Mockery::mock();
        $initialReg->shouldReceive('handle')->once()->with('', '123')->andReturn(true);
        $this->container->shouldReceive('get')->with('initialRegistrationHandler')->andReturn($initialReg);

        $this->mockState('');

        $this->invokeHandleMessage($this->makeAction(), '', '123');
    }

    // ============================================================
    // UnsupportedTypeHandler
    // ============================================================

    public function testHandleMessageStopsOnUnsupportedTypeHandler(): void
    {
        $this->mockBotStarted();
        $this->mockCommandHandlerWithStartAndDelete(false);
        $this->mockRegistrationHandler(false);
        $this->mockInitialReg(false);

        $unsupported = Mockery::mock();
        $unsupported->shouldReceive('handle')
            ->once()->with('голосовое', [], '123', State::Request_Created->value)
            ->andReturn(true);
        $this->container->shouldReceive('get')->with('unsupportedTypeHandler')->andReturn($unsupported);

        $this->mockState(State::Request_Created->value);

        $this->invokeHandleMessage($this->makeAction(), 'голосовое', '123');
    }

    // ============================================================
    // CommentHandler
    // ============================================================

    public function testHandleMessageStopsOnCommentHandler(): void
    {
        $this->mockBotStarted();
        $this->mockCommandHandlerWithStartAndDelete(false);
        $this->mockRegistrationHandler(false);
        $this->mockInitialReg(false);
        $this->mockUnsupported(false);
        $this->mockFileAttachmentAllFalse();

        $commentHandler = Mockery::mock();
        $commentHandler->shouldReceive('handleComment')
            ->once()->andReturn(true);
        $this->container->shouldReceive('get')->with('commentHandler')->andReturn($commentHandler);

        $this->mockState(State::Request_Created->value);

        $this->invokeHandleMessage($this->makeAction(), 'текст комментария', '123');
    }

    // ============================================================
    // FinishRequestHandler
    // ============================================================

    public function testHandleMessageStopsOnFinishRequestHandler(): void
    {
        $this->mockBotStarted();
        $this->mockCommandHandlerWithStartAndDelete(false);
        $this->mockRegistrationHandler(false);
        $this->mockInitialReg(false);
        $this->mockUnsupported(false);
        $this->mockFileAttachmentAllFalse();

        $commentHandler = Mockery::mock();
        $commentHandler->shouldReceive('handleComment')->once()->andReturn(false);
        $this->container->shouldReceive('get')->with('commentHandler')->andReturn($commentHandler);

        $finishHandler = Mockery::mock();
        $finishHandler->shouldReceive('handle')->once()->andReturn(true);
        $this->container->shouldReceive('get')->with('finishRequestHandler')->andReturn($finishHandler);

        $this->mockState(State::Request_Created->value);

        $this->invokeHandleMessage($this->makeAction(), State::Finish_Request->value, '123');
    }

    // ============================================================
    // IssueCloseHandler
    // ============================================================

public function testHandleMessageStopsOnIssueCloseHandler(): void
{
    $this->mockBotStarted();
    $this->mockFullCommandHandler();
    $this->mockRegistrationHandler(false);
    $this->mockInitialReg(false);
    $this->mockUnsupported(false);
    $this->mockFileAttachmentAllFalse();
    $this->mockCommentHandler(false);
    $this->mockFinishHandler(false);

    $issueClose = Mockery::mock();
    $issueClose->shouldReceive('handleManuallyCloseRequest')->once()->andReturn(true);
    $this->container->shouldReceive('get')->with('issueCloseHandler')->andReturn($issueClose);

    $this->mockState(State::Request_Created->value);

    $this->invokeHandleMessage($this->makeAction(), State::Yes->value, '123');
}
    // ============================================================
    // MenuTransitionHandler
    // ============================================================

    public function testHandleMessageStopsOnMenuTransitionHandler(): void
    {
        $this->mockBotStarted();
        $this->mockFullCommandHandler();
        $this->mockRegistrationHandler(false);
        $this->mockInitialReg(false);
        $this->mockUnsupported(false);
        $this->mockFileAttachmentAllFalse();
        $this->mockCommentHandler(false);
        $this->mockFinishHandler(false);
        $this->mockIssueClose(false);

        $menuTransition = Mockery::mock();
        $menuTransition->shouldReceive('handle')->once()->andReturn(true);
        $this->container->shouldReceive('get')->with('menuTransitionHandler')->andReturn($menuTransition);

        $this->mockState(State::Request_Created->value);

        $this->invokeHandleMessage($this->makeAction(), State::Create_Request->value, '123');
    }

    // ============================================================
    // Fallback → UnknownTextHandler
    // ============================================================

    public function testHandleMessageFallsBackToUnknownTextHandler(): void
    {
        $this->mockBotStarted();
        $this->mockFullCommandHandler();
        $this->mockRegistrationHandler(false);
        $this->mockInitialReg(false);
        $this->mockUnsupported(false);
        $this->mockFileAttachmentAllFalse();
        $this->mockCommentHandler(false);
        $this->mockFinishHandler(false);
        $this->mockIssueClose(false);

        $menuTransition = Mockery::mock();
        $menuTransition->shouldReceive('handle')->andReturn(false);
        $menuTransition->shouldReceive('handleCreateNew')->andReturn(false);
        $this->container->shouldReceive('get')->with('menuTransitionHandler')->andReturn($menuTransition);

        $unknownText = Mockery::mock();
        $unknownText->shouldReceive('handle')->once()->with('случайный текст', '123');
        $this->container->shouldReceive('get')->with('unknownTextHandler')->andReturn($unknownText);

        // Fallback: processStateFromText → stateManagerHandler->processState
        $this->stateManagerHandler->shouldReceive('processState')->andReturnNull();

        $this->mockState(State::Request_Created->value);

        $this->invokeHandleMessage($this->makeAction(), 'случайный текст', '123');
    }

    // ============================================================
    // Вспомогательные методы
    // ============================================================

    private function makeAction(): ListHooksAction
    {
        return new ListHooksAction(
            $this->logger,
            $this->container,
            $this->maxBot,
            $this->menuCreateAction,
            $this->stateFileHandler,
            $this->okdeskHelper,
            $this->stateManagerHandler,
            $this->qrCodeHelper
        );
    }

    private function invokeHandleMessage(
        ListHooksAction $action,
        string $text,
        string $chatId,
        array $filesInfo = [],
        array $message = []
    ): void {
        $ref = new \ReflectionClass($action);
        $method = $ref->getMethod('handleMessage');
        $method->setAccessible(true);
        $method->invoke($action, $text, $chatId, $filesInfo, $message);
    }

    // ---- Моки отдельных хендлеров ----

    private function mockBotStarted(): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handle')->andReturnNull();
        $this->container->shouldReceive('get')->with('botStartedHandler')->andReturn($mock);
    }

    private function mockCommandHandlerWithStartAndDelete(bool $result): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handleStartAndDelete')->andReturn($result);
        $this->container->shouldReceive('get')->with('commandHandler')->andReturn($mock);
    }

    private function mockFullCommandHandler(): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handleStartAndDelete')->andReturn(false);
        $mock->shouldReceive('handleCommandIfRequestWithoutAuth')->andReturn(false);
        $mock->shouldReceive('handleOkForStateCommand')->andReturn(false);
        $mock->shouldReceive('handleCommandIfRequestExist')->andReturn(false);
        $mock->shouldReceive('handleCommands')->andReturn(false);
        $mock->shouldReceive('handleUnknownTextIfRequestExist')->andReturn(false);
        $this->container->shouldReceive('get')->with('commandHandler')->andReturn($mock);
    }

    private function mockRegistrationHandler(bool $result): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handleContactMessage')->andReturn($result);
        $this->container->shouldReceive('get')->with('registrationHandler')->andReturn($mock);
    }

    private function mockInitialReg(bool $result): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handle')->andReturn($result);
        $this->container->shouldReceive('get')->with('initialRegistrationHandler')->andReturn($mock);
    }

    private function mockUnsupported(bool $result): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handle')->andReturn($result);
        $this->container->shouldReceive('get')->with('unsupportedTypeHandler')->andReturn($mock);
    }

    private function mockFileAttachmentAllFalse(): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('addFileAttachmentsToIssue')->andReturn(false);
        $mock->shouldReceive('handleTechSupportFile')->andReturn(false);
        $mock->shouldReceive('handleReturnToStart')->andReturn(false);
        $mock->shouldReceive('handleBackState')->andReturn(false);
        $mock->shouldReceive('handleFileRestriction')->andReturn(false);
        $this->container->shouldReceive('get')->with('fileAttachmentHandler')->andReturn($mock);
    }

    private function mockCommentHandler(bool $result): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handleComment')->andReturn($result);
        $this->container->shouldReceive('get')->with('commentHandler')->andReturn($mock);
    }

    private function mockFinishHandler(bool $result): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handle')->andReturn($result);
        $this->container->shouldReceive('get')->with('finishRequestHandler')->andReturn($mock);
    }

    private function mockIssueClose(bool $result): void
    {
        $mock = Mockery::mock();
        $mock->shouldReceive('handleManuallyCloseRequest')->andReturn($result);
        $this->container->shouldReceive('get')->with('issueCloseHandler')->andReturn($mock);
    }

    private function mockState(string $state): void
    {
        $this->stateFileHandler->shouldReceive('getStateFromFile')
            ->with('123')->andReturn(['state' => $state]);
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'file_links')->andReturn([]);
    }
}