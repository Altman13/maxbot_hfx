<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\FinishRequestHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class FinishRequestHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBot;
    private FinishRequestHandler $handler;

    protected function setUp(): void
    {
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->handler = new FinishRequestHandler($this->menuCreateAction, $this->maxBot);
    }

    public function testHandleReturnsTrueAndSendsMenuForFinishRequest(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Are you sure?', 'reply_markup' => []];

        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->with(
                '123',
                State::Issue_AlReady_Exist,
                ResponseMessage::Are_You_Sure->value,
                'inline'
            )
            ->andReturn($menu);

        $this->maxBot
            ->shouldReceive('sendMenu')
            ->once()
            ->with($menu);

        $result = $this->handler->handle(State::Finish_Request->value, '123');

        $this->assertTrue($result);
    }

    public function testHandleReturnsFalseForOtherText(): void
    {
        $this->menuCreateAction->shouldNotReceive('createMenuLogicWithKeyboardType');
        $this->maxBot->shouldNotReceive('sendMenu');

        $result = $this->handler->handle('что-то другое', '123');

        $this->assertFalse($result);
    }

    public function testHandleReturnsFalseForEmptyText(): void
    {
        $result = $this->handler->handle('', '123');

        $this->assertFalse($result);
    }
}