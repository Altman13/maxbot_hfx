<?php

namespace App\Application\Actions\Menu;

use App\Application\Handlers\StateFileHandler;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;

class MenuCreateAction
{
    private const KEYBOARD_TYPE_INLINE = 'inline';
    private const KEYBOARD_TYPE_REPLY = 'reply';

    public function __construct(
        private StateFileHandler $stateFileHandler
    ) {}

    public function createMenuLogic(string $chatId, State $currentState, string $responseMessage): array
    {
        $previousState = $this->getPreviousState($chatId);

        if (!$this->isValidTransition($previousState, $currentState)) {
            return $this->createInvalidResponse($chatId, $previousState?->value ?? 'none', $currentState->value);
        }

        return $this->createValidResponse($chatId, $currentState, $responseMessage);
    }

    public function createMenuLogicWithKeyboardType(
        string $chatId,
        State $currentState,
        string $responseMessage,
        string $keyboardType = self::KEYBOARD_TYPE_REPLY,
        ?array $customButtons = null
    ): array {
        $previousState = $this->getPreviousState($chatId);

        if (!$this->isValidTransition($previousState, $currentState)) {
            return $this->createInvalidResponse($chatId, $previousState?->value ?? 'none', $currentState->value);
        }

        $buttons = $customButtons ?? $this->createButtons($currentState);

        $this->saveState($chatId, $currentState);

        $response = [
            'chat_id' => $chatId,
            'text' => $responseMessage,
        ];

        if (!empty($buttons)) {
            $response['reply_markup'] = $this->formatKeyboardForMax($buttons, $keyboardType);
        }

        return $response;
    }

    private function formatKeyboardForMax(array $buttons, string $keyboardType): array
    {
        $formattedButtons = [];
        
        foreach (array_chunk($buttons, 1) as $rowButtons) {
            $row = [];
            foreach ($rowButtons as $button) {
                if ($keyboardType === self::KEYBOARD_TYPE_INLINE && isset($button['callback_data'])) {
                    $row[] = [
                        'text' => $button['text'],
                        'callback_data' => $button['callback_data']
                    ];
                } else {
                    $row[] = ['text' => $button['text']];
                }
            }
            $formattedButtons[] = $row;
        }

        return [
            'keyboard' => $formattedButtons,
            'resize_keyboard' => true,
            'one_time_keyboard' => ($keyboardType === self::KEYBOARD_TYPE_REPLY)
        ];
    }

    // ==================== PRIVATE METHODS ====================

    private function getPreviousState(string $chatId): ?State
    {
        $previousStateValue = $this->stateFileHandler->getStateFromFile($chatId);

        if ($previousStateValue && isset($previousStateValue["state"])) {
            return State::from($previousStateValue["state"]);
        }

        return null;
    }

    private function isValidTransition(?State $previousState, State $currentState): bool
    {
        if (!$previousState) {
            return true;
        }

        return $this->isStandardTransition($previousState, $currentState)
            || $this->isSpecialTransition($previousState, $currentState);
    }

    private function isStandardTransition(State $previousState, State $currentState): bool
    {
        $stateTransitions = $this->getStateTransitions();

        return isset($stateTransitions[$previousState->value])
            && in_array($currentState, $stateTransitions[$previousState->value], true);
    }

    private function isSpecialTransition(State $previousState, State $currentState): bool
    {
        $specialTransitions = [
            [State::Request_Created, State::Request_Created],
            [State::Close_Command, State::Close_Command],
            [State::Issue_AlReady_Exist, State::Issue_AlReady_Exist],
            [State::Main_Menu, State::Main_Menu],

            [State::Request_Created, State::Create_New],
            [State::Expected_Plotter_Number, State::Create_Request_Without_Auth],
            [State::Expected_Plotter_Number, State::Main_Menu],
            [State::Create_Request_Without_Auth, State::Main_Menu],
            [State::Main_Menu, State::Expected_Plotter_Number],
            [State::Main_Command, State::Main_Menu],
            [State::Main_Command, State::Create_Request_Without_Auth],
            [State::Request_Created, State::Main_Menu],
            [State::Close_Command, State::Main_Menu],
            [State::Return_To_Start, State::Main_Menu],
            [State::Scan_Plotter_QR_Code, State::Main_Menu],
            [State::Finish_Request, State::Main_Menu],
            [State::Expected_Plotter_Number, State::Return_To_Start],
            [State::Return_To_Start, State::Scan_Plotter_QR_Code],
            [State::Unlock_Cutter, State::Main_Menu],
            [State::Expected_Plotter_Number, State::Return_To_Start],
            [State::Expected_Plotter_Number, State::Main_Command],
            [State::Request_Created, State::Issue_AlReady_Exist],
            [State::Issue_AlReady_Exist, State::Request_Created],
            [State::Create_New, State::Create_Request],
            [State::Create_New, State::Request_Created],
            [State::Request_Created, State::Create_Request],
            [State::Main_Menu, State::Create_New],
            [State::Main_Menu, State::Request_Created],
            [State::Main_Command, State::Request_Created],
            [State::Main_Menu, State::Issue_AlReady_Exist],
            [State::Unlock_Cutter, State::Return_To_Start],

            [State::Share_Contact, State::Main_Menu],
        ];

        foreach ($specialTransitions as [$from, $to]) {
            if ($previousState->value === $from->value && $currentState->value === $to->value) {
                return true;
            }
        }

        return false;
    }

    private function getStateTransitions(): array
    {
        return [
            State::Start_Command->value              => [State::Share_Contact],
            State::Start->value                      => [State::Share_Contact],
            State::Main_Menu->value                  => [State::Create_Request, State::Unlock_Cutter],
            State::Create_Request->value             => [State::Scan_Plotter_QR_Code, State::Return_To_Start],
            State::Expected_Plotter_Number->value    => [State::Scan_Plotter_QR_Code, State::Return_To_Start],
            State::Scan_Plotter_QR_Code->value       => [State::Return_To_Start],
            State::Cut_By_QR_Code->value             => [State::GoToBack],
            State::Request_Created->value            => [State::Finish_Request, State::Return_To_Start],
            State::Create_Request_Without_Auth->value => [State::Create_Request_Without_Auth, State::Return_To_Start],
            State::Finish_Request->value             => [State::Create_Request, State::Unlock_Cutter],
            State::Close_Command->value              => [State::Yes, State::No],
            State::No->value                         => [State::Finish_Request, State::Return_To_Start],
            State::Issue_AlReady_Exist->value        => [State::Yes, State::No],
            State::Create_New->value                 => [State::Create_New, State::Stay],
        ];
    }

    private function createValidResponse(string $chatId, State $currentState, string $responseMessage): array
    {
        $buttons = $this->createButtons($currentState);

        $this->saveState($chatId, $currentState);

        return [
            'chat_id' => $chatId,
            'text' => $responseMessage,
            'reply_markup' => $this->formatKeyboardForMax($buttons, self::KEYBOARD_TYPE_REPLY)
        ];
    }

    private function createInvalidResponse(string $chatId, string $previousState, string $currentState): array
    {
        if (!empty($_ENV['DEBUG_ENV'])) {
            $errorMessage = sprintf(
                "[%s] Invalid state transition: Chat %s, From %s to %s" . PHP_EOL,
                date('Y-m-d H:i:s'),
                $chatId,
                $previousState,
                $currentState
            );
            $stateErrorPath = dirname(__DIR__, 4) . '/debug/state_errors.log';
            $debugDir = dirname(__DIR__, 4) . '/debug';

            if (!is_dir($debugDir)) {
                mkdir($debugDir, 0777, true);
            }

            file_put_contents($stateErrorPath, $errorMessage, FILE_APPEND);
        }

        $this->stateFileHandler->setStateField($chatId, 'State', $previousState);
        
        return [
            'chat_id' => $chatId,
            'text' => ResponseMessage::Error_Occurred->value,
        ];
    }

    private function saveState(string $chatId, State $state): void
    {
        $this->stateFileHandler->saveStateToFile($chatId, [
            'state' => $state->value,
            'previous_state' => null
        ]);
    }

    private function createButtons(State $currentState): array
    {
        $buttons = [];
        $stateTransitions = $this->getStateTransitions();

        if (!isset($stateTransitions[$currentState->value])) {
            return $buttons;
        }

        foreach ($stateTransitions[$currentState->value] as $nextState) {
            if ($currentState === State::Share_Contact) {
                $buttons[] = ['text' => State::Share_Contact->value, 'request_contact' => true];
            } else {
                $buttons[] = ['text' => $nextState->value, 'callback_data' => $nextState->value];
            }
        }

        return $buttons;
    }
}