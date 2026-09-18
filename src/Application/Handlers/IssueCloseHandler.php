<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Exception;
use Psr\Log\LoggerInterface;

class IssueCloseHandler
{
    private const KEYBOARD_TYPE_INLINE = 'inline';

    public function __construct(
        private LoggerInterface $logger,
        private StateFileHandler $stateFileHandler,
        private MenuCreateAction $menuCreateAction,
        private MaxBotHelper $maxBot,
        private OkDeskHelper $okdeskHelper
    ) {}

    public function handleManuallyCloseRequest(string $text, string $currentState, string $chatId): bool
    {
        if ($text === State::No->value && $currentState === State::Issue_AlReady_Exist->value) {
            return $this->handleNegativeResponse($chatId);
        }

        if ($text === State::Yes->value && $currentState === State::Issue_AlReady_Exist->value) {
            return $this->handlePositiveResponse($chatId);
        }
        return false;
    }

    private function handlePositiveResponse(string $chatId): bool
    {
        $issueId = (int)$this->stateFileHandler->getStateField($chatId, 'issueId');

        if (empty($issueId)) {
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::No_Open_Request->value);
            return false;
        }

        if (!$this->closeIssue($issueId, $chatId)) {
            return false;
        }

        $requestStateFields = [
            'issueId',
            'lastComment',
            'attachmentFileName',
            'file_links',
            'last_file_batch_time',
            'inventory_number'
        ];

        foreach ($requestStateFields as $field) {
            $this->stateFileHandler->clearStateKey($chatId, $field);
        }

        $confirmationMessage = $this->createConfirmationMessage($issueId);
        $this->maxBot->sendMessage((int)$chatId, $confirmationMessage);

        $this->updateUserStateAndSendMenu(
            $chatId,
            State::Finish_Request,
            State::Main_Menu,
            ResponseMessage::Main_Menu->value
        );

        return true;
    }

    private function handleNegativeResponse(string $chatId): bool
    {
        $this->stateFileHandler->setStateField($chatId, 'state', State::Request_Created->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Request_Created,
            ResponseMessage::Stayed_In_Current_Issue->value,
            self::KEYBOARD_TYPE_INLINE
        );

        $this->maxBot->sendMenu($menu);

        return true;
    }

    private function closeIssue(int $issueId, string $chatId): bool
    {
        try {
            $contactId = $this->stateFileHandler->getStateField($chatId, 'contactId');
            $this->okdeskHelper->addCommentToIssue(
                $issueId,
                ResponseMessage::User_Closed_Request->value,
                (int)$contactId
            );
            $this->stateFileHandler->clearStateKey($chatId, 'issueId');
            return true;
        } catch (Exception $e) {
            error_log("Error closing issue {$issueId}: " . $e->getMessage());
            return false;
        }
    }

    private function createConfirmationMessage(int $issueId): string
    {
        $issueDetails = $this->okdeskHelper->fetchIssueDetails($issueId);

        $issueNumber = $issueDetails['id'] ?? $issueId;
        $issueDate = $this->okdeskHelper->formatCreatedAt($issueDetails['created_at'] ?? '');

        return str_replace(
            ['{{номер}}', '{{дата}}'],
            [$issueNumber, $issueDate],
            ResponseMessage::Manually_Closing_Request->value
        );
    }

    private function updateUserStateAndSendMenu(
        string $chatId,
        State $newState,
        State $menuState,
        string $message
    ): void {
        $this->stateFileHandler->setStateField($chatId, 'state', $newState->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            $menuState,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
    }
}