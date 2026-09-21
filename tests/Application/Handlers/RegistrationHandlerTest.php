<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\RegistrationHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\StateManagerHandler;
use App\Application\Helpers\ArmHelper;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegistrationHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LoggerInterface $logger;
    private OkDeskHelper $okDeskHelper;
    private StateFileHandler $stateFileHandler;
    private ArmHelper $armHelper;
    private MaxBotHelper $maxBot;
    private StateManagerHandler $stateManagerHandler;
    private MenuCreateAction $menuCreateAction;
    private RegistrationHandler $handler;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->okDeskHelper = Mockery::mock(OkDeskHelper::class);
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->armHelper = Mockery::mock(ArmHelper::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->stateManagerHandler = Mockery::mock(StateManagerHandler::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);

        $this->handler = new RegistrationHandler(
            $this->logger,
            $this->okDeskHelper,
            $this->stateFileHandler,
            $this->armHelper,
            $this->maxBot,
            $this->stateManagerHandler,
            $this->menuCreateAction
        );
    }

    // ============================================================
    // handleContactMessage — negative cases
    // ============================================================

    public function testHandleContactMessageReturnsFalseWhenStateNotEmpty(): void
    {
        $result = $this->handler->handleContactMessage([], '123', State::Main_Menu->value);

        $this->assertFalse($result);
    }

    public function testHandleContactMessageSendsMessageWhenNotContact(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once();

        $result = $this->handler->handleContactMessage([], '123', '');

        $this->assertFalse($result);
    }

    public function testHandleContactMessageSendsErrorWhenContactNotOwn(): void
    {
        $message = [
            'message' => [
                'sender' => ['user_id' => 999],
                'body'   => [
                    'attachments' => [
                        [
                            'type'    => 'contact',
                            'payload' => [
                                'vcf_info' => "TEL:+79991234567",
                                'max_info' => ['user_id' => 111],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::pattern('/Ошибка авторизации/'));

        $result = $this->handler->handleContactMessage($message, '123', '');

        $this->assertTrue($result);
    }

    public function testHandleContactMessageSendsErrorWhenNoMaxInfo(): void
    {
        $message = [
            'message' => [
                'sender' => ['user_id' => 999],
                'body'   => [
                    'attachments' => [
                        [
                            'type'    => 'contact',
                            'payload' => ['vcf_info' => "TEL:+79991234567"],
                        ],
                    ],
                ],
            ],
        ];

        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::pattern('/из телефонной книги/'));

        $result = $this->handler->handleContactMessage($message, '123', '');

        $this->assertTrue($result);
    }

    // ============================================================
    // handleContactMessage — positive cases
    // ============================================================

    public function testHandleContactMessageRegistersExternalUser(): void
    {
        $message = [
            'message' => [
                'sender' => ['user_id' => 111],
                'body'   => [
                    'attachments' => [
                        [
                            'type'    => 'contact',
                            'payload' => [
                                'vcf_info' => "TEL:+79991234567",
                                'max_info' => [
                                    'user_id'    => 111,
                                    'first_name' => 'Иван',
                                    'last_name'  => 'Иванов',
                                    'name'       => 'Иван Иванов',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // setStateField — state = Share_Contact (внутри handleContactMessage)
        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'state', State::Share_Contact->value)
            ->once();

        // armHelper: не сотрудник
        $this->armHelper
            ->shouldReceive('findWorkerByPhone')
            ->with('+79991234567')
            ->andReturn(false);

        // setStateField — role = contact
        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'role', 'contact')
            ->once();

        // handleUserRegistrationAfterContact — getStateField 'state'
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'state')
            ->andReturn(State::Share_Contact->value);

        // okDeskHelper: user exists
        $this->okDeskHelper
            ->shouldReceive('findUserByPhone')
            ->with('+79991234567')
            ->andReturn(['id' => 456, 'company_id' => 100]);

        // setStateField — companyId, contactId
        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'companyId', 100)
            ->once();

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'contactId', 456)
            ->once();

        // Main_Menu
        $menu = ['chat_id' => '123', 'text' => 'Main', 'reply_markup' => []];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->andReturn([]);

        // phone_number
        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'phone_number', '+79991234567')
            ->once();

        $result = $this->handler->handleContactMessage($message, '123', '');

        $this->assertTrue($result);
    }

    public function testHandleContactMessageRegistersEmployee(): void
    {
        $message = [
            'message' => [
                'sender' => ['user_id' => 111],
                'body'   => [
                    'attachments' => [
                        [
                            'type'    => 'contact',
                            'payload' => [
                                'vcf_info' => "TEL:+79991234567",
                                'max_info' => [
                                    'user_id' => 111,
                                    'name'    => 'Иван Иванов',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'state', State::Share_Contact->value)
            ->once();

        // armHelper: сотрудник
        $this->armHelper
            ->shouldReceive('findWorkerByPhone')
            ->with('+79991234567')
            ->andReturn(true);

        // role = employee
        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'role', 'employee')
            ->once();

        // handleUserByPhoneNumber — okDesk
        $this->okDeskHelper
            ->shouldReceive('findUserByPhone')
            ->with('+79991234567')
            ->andReturn(['id' => 456, 'company_id' => 100]);

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'companyId', 100)
            ->once();

        $this->stateFileHandler            ->shouldReceive('setStateField')
            ->with('123', 'contactId', 456)
            ->once();

        // menu
        $menu = ['chat_id' => '123', 'text' => 'Main', 'reply_markup' => []];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')->andReturn($menu);
        $this->maxBot->shouldReceive('sendMenu')->andReturn([]);

        // phone_number
        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'phone_number', '+79991234567')
            ->once();

        // handleUserRegistrationAfterContact не сработает для employee (нет role=contact),
        // но вызывает getStateField 'state' — вернём что-то отличное от Share_Contact
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'state')
            ->andReturn('Main_Menu');

        $result = $this->handler->handleContactMessage($message, '123', '');

        $this->assertTrue($result);
    }

    // ============================================================
    // checkUserByPhoneNumber
    // ============================================================

    public function testCheckUserByPhoneNumberDelegatesToOkDesk(): void
    {
        $this->okDeskHelper
            ->shouldReceive('findUserByPhone')
            ->with('+79991234567')
            ->andReturn(['id' => 456]);

        $result = $this->handler->checkUserByPhoneNumber('+79991234567');

        $this->assertSame(456, $result['id']);
    }

    // ============================================================
    // isEmployee
    // ============================================================

    public function testIsEmployeeDelegatesToArmHelper(): void
    {
        $this->armHelper
            ->shouldReceive('findWorkerByPhone')
            ->with('+79991234567')
            ->andReturn(true);

        $this->assertTrue($this->handler->isEmployee('+79991234567'));
    }

    // ============================================================
    // getUserRole
    // ============================================================

    public function testGetUserRoleReturnsRoleFromState(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'role')
            ->andReturn('employee');

        $this->assertSame('employee', $this->handler->getUserRole('123'));
    }

    public function testGetUserRoleReturnsUnknownWhenNoRole(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'role')
            ->andReturn('');

        $this->assertSame('unknown', $this->handler->getUserRole('123'));
    }

    // ============================================================
    // isUserRegistered
    // ============================================================

    public function testIsUserRegisteredReturnsTrueWhenContactIdExists(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('456');

        $this->assertTrue($this->handler->isUserRegistered('123'));
    }

    public function testIsUserRegisteredReturnsFalseWhenContactIdEmpty(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('');

        $this->assertFalse($this->handler->isUserRegistered('123'));
    }

    // ============================================================
    // requestContact
    // ============================================================

    public function testRequestContactCallsMaxBot(): void
    {
        $this->maxBot
            ->shouldReceive('requestContact')
            ->once()
            ->with(123, Mockery::type('string'));

        $this->handler->requestContact('123');
    }
}