<?php

declare(strict_types=1);

namespace App\Application\Handlers;
use App\Application\Helpers\OkDeskHelper;
use App\Application\State\State;
use Psr\Log\LoggerInterface;

/**
 * Обрабатывает комментарии к заявкам
 */
class CommentHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private OkDeskHelper $okDeskHelper,
        private StateFileHandler $stateFileHandler,
    ) {}

    /**
     * Точка входа из фасада. Вызывается на шаге 9.
     */
    public function handleComment(
        string $text,
        string $chatId,
        string $currentState,
        array $validCurrentStates,
        array $invalidTextStates
    ): bool {
        if (
            !$text
            || !in_array($currentState, $validCurrentStates, true)
            || in_array($text, $invalidTextStates, true)
        ) {
            return false;
        }

        $isOtherStateValue = false;
        foreach (State::getAllValues() as $stateValue) {
            if (
                $stateValue === $text
                && $stateValue !== State::Yes->value
                && $stateValue !== State::No->value
            ) {
                $isOtherStateValue = true;
                break;
            }
        }

        if (!$isOtherStateValue) {
            $this->handleCommentAddition($chatId, $text);
            return true;
        }

        return false;
    }

    /**
     * Добавляет комментарий в OkDesk.
     */
    private function handleCommentAddition(string $chatId, string $text): void
    {
        $contactId = $this->stateFileHandler->getStateField($chatId, 'contactId');
        $currentIssue = $this->stateFileHandler->getStateField($chatId, 'issueId');

        if ($currentIssue) {
            $this->okDeskHelper->addCommentToIssue((int)$currentIssue, $text, (int)$contactId);
        }
    }

    /**
     * Публичный метод для обратной совместимости со старым API.
     */
    public function addComment(string $chatId, string $text, bool $checkFinalStatus = true): bool
    {
        $issueId = $this->stateFileHandler->getStateField($chatId, 'issueId');
        if (!$issueId) {
            $this->logger->warning("No issue found for comment in chat: {$chatId}");
            return false;
        }

        $contactId = (string)$this->stateFileHandler->getStateField($chatId, 'contactId');
        $result = $this->okDeskHelper->addCommentToIssue((int)$issueId, $text, (int)$contactId);

        if ($result) {
            $this->logger->info("Comment added to issue {$issueId} for chat: {$chatId}");
            return true;
        }

        $this->logger->error("Failed to add comment to issue {$issueId} for chat: {$chatId}");
        return false;
    }
}