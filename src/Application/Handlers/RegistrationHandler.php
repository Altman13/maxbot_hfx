<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Helpers\ArmHelper;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Psr\Log\LoggerInterface;

/**
 * Обрабатывает регистрацию пользователей
 */
class RegistrationHandler
{
    private const KEYBOARD_TYPE_INLINE = 'inline';

    private LoggerInterface $logger;
    private OkDeskHelper $okDeskHelper;
    private StateFileHandler $stateFileHandler;
    private ArmHelper $armHelper;
    private MaxBotHelper $maxBot;
    private StateManagerHandler $stateManagerHandler;
    private MenuCreateAction $menuCreateAction;

    public function __construct(
        LoggerInterface $logger,
        OkDeskHelper $okDeskHelper,
        StateFileHandler $stateFileHandler,
        ArmHelper $armHelper,
        MaxBotHelper $maxBot,
        StateManagerHandler $stateManagerHandler,
        MenuCreateAction $menuCreateAction
    ) {
        $this->logger = $logger;
        $this->okDeskHelper = $okDeskHelper;
        $this->stateFileHandler = $stateFileHandler;
        $this->armHelper = $armHelper;
        $this->maxBot = $maxBot;
        $this->stateManagerHandler = $stateManagerHandler;
        $this->menuCreateAction = $menuCreateAction;
    }

    /**
     * Обрабатывает контактное сообщение при регистрации.
     *
     * @param array  $message      Полный JSON сообщения
     * @param string $chatId       ID чата
     * @param string $currentState Текущее состояние
     * @return bool true — если сообщение содержало контакт и обработано
     */
    public function handleContactMessage(array $message, string $chatId, string $currentState): bool
    {
        $this->logger->info("handleContactMessage for chat: {$chatId}, state: {$currentState}");

        if ($currentState != '') {
            return false;
        }

        if (
            !isset($message['message']['body']['attachments'][0]['type']) ||
            $message['message']['body']['attachments'][0]['type'] !== 'contact'
        ) {
            $this->maxBot->sendMessage(
                (int)$chatId,
                ResponseMessage::Request_Contact_Share_File_Case->value
            );
            return false;
        }

        file_put_contents(
            'contact.json',
            json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $vcfInfo = $message['message']['body']['attachments'][0]['payload']['vcf_info'] ?? '';
        $maxInfo = $message['message']['body']['attachments'][0]['payload']['max_info'] ?? [];

        $senderUserId = $message['message']['sender']['user_id'] ?? null;

        // Проверка, что контакт принадлежит отправителю
        if (!empty($maxInfo)) {
            if (isset($maxInfo['user_id']) && $maxInfo['user_id'] != $senderUserId) {
                $errorMessage = "❌ Ошибка авторизации: вы поделились контактом другого пользователя.\n\n";
                $errorMessage .= "Пожалуйста, поделитесь СВОИМ контактом, нажав на кнопку \"Переслать\" и выбрав свой контакт из списка.";
                $this->maxBot->sendMessage((int)$chatId, $errorMessage);
                return true;
            }
        } else {
            // Нет max_info — это контакт из телефонной книги
            $errorMessage = "❌ Ошибка авторизации: вы поделились контактом из телефонной книги.\n\n";
            $errorMessage .= "Пожалуйста, поделитесь СВОИМ контактом, нажав на кнопку \"Переслать\" и выбрав свой контакт из списка.";
            $this->maxBot->sendMessage((int)$chatId, $errorMessage);
            return true;
        }

        // Извлекаем номер телефона из vcf
        $phone = '';
        if (preg_match('/TEL[^:]*:([0-9+]+)/', $vcfInfo, $matches)) {
            $phone = $matches[1];
            if (strpos($phone, '+') !== 0) {
                $phone = '+' . $phone;
            }
        }

        // Извлекаем имя
        $firstName = $maxInfo['first_name'] ?? '';
        $lastName  = $maxInfo['last_name'] ?? '';
        $fullName  = $maxInfo['name'] ?? '';

        if (empty($firstName) && preg_match('/FN:(.+)/', $vcfInfo, $nameMatches)) {
            $fullName = trim($nameMatches[1]);
            $nameParts = explode(' ', $fullName, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName  = $nameParts[1] ?? '';
        }

        $contact = [
            'phone_number' => $phone,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'full_name'    => $fullName,
            'user_id'      => $maxInfo['user_id'] ?? null,
        ];

        if ($phone) {
            $this->stateFileHandler->setStateField($chatId, 'state', State::Share_Contact->value);
            $this->handleContact($contact, $chatId, $currentState);
            $this->stateFileHandler->setStateField($chatId, 'phone_number', $phone);
            $this->handleUserRegistrationAfterContact($phone, $chatId, $currentState, $contact);
        } else {
            $this->maxBot->sendMessage((int)$chatId, "❌ Не удалось получить номер. Попробуйте еще раз.");
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Register->value);
        }

        return true;
    }

    /**
     * Обрабатывает контакт: определяет, сотрудник или внешний пользователь.
     */
    private function handleContact(array $contact, string $chatId, string $currentState): void
    {
        $this->logger->info("handleContact for chat: {$chatId}");
        $phoneNumber = $contact['phone_number'];

        if ($this->armHelper->findWorkerByPhone($phoneNumber)) {
            // Свой сотрудник
            $this->stateFileHandler->setStateField($chatId, 'role', 'employee');
            $this->handleUserByPhoneNumber($phoneNumber, $chatId);

            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Main_Menu,
                ResponseMessage::Main_Menu->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
        } else {
            // Внешний контакт
            $this->stateFileHandler->setStateField($chatId, 'role', 'contact');
            $this->handleUserRegistrationAfterContact($phoneNumber, $chatId, $currentState, $contact);
        }
    }



    /**
     * Регистрирует пользователя после получения контакта.
     */
    private function handleUserRegistrationAfterContact(
        string $phoneNumber,
        string $chatId,
        string $currentState,
        array $contact
    ): bool {
        $this->logger->info("handleUserRegistrationAfterContact for chat: {$chatId}");

        $currentState = $this->stateFileHandler->getStateField($chatId, 'state');

        if ($currentState === State::Share_Contact->value) {
            $data = $this->okDeskHelper->findUserByPhone($phoneNumber);

            if (!$data) {
                $data = $this->okDeskHelper->createNewUser(
                    [],
                    $contact['phone_number'],
                    $contact['first_name'],
                    $contact['last_name'] ?? ''
                );
            }

            $contactId = $data['id'] ?? null;
            $companyId = $data['company_id'] ?? null;

            $this->stateFileHandler->setStateField($chatId, 'companyId', $companyId);
            $this->stateFileHandler->setStateField($chatId, 'contactId', $contactId);

            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Main_Menu,
                ResponseMessage::Main_Menu->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        return false;
    }

    /**
     * Обрабатывает контактное сообщение от пользователя (старый API).
     */
    public function handleContactLegacy(array $message, string $chatId, string $currentState): void
    {
        $this->logger->info("Handling contact for chat: {$chatId}");

        $contact = $this->extractContactInfo($message);
        if (!$contact) {
            $this->maxBot->sendMessage((int)$chatId, "❌ Не удалось получить контакт. Попробуйте еще раз.");
            return;
        }

        if (!$this->validateContactOwnership($message, $contact)) {
            $this->sendOwnershipError($chatId);
            return;
        }

        $phone = $contact['phone_number'];
        $this->stateFileHandler->setStateField($chatId, 'phone_number', $phone);

        if ($this->armHelper->findWorkerByPhone($phone)) {
            $this->handleEmployeeRegistration($phone, $chatId);
        } else {
            $this->handleExternalUserRegistration($phone, $chatId, $currentState, $contact);
        }
    }

    private function extractContactInfo(array $message): ?array
    {
        $attachments = $this->getAttachmentsFromMessage($message);

        if (empty($attachments)) {
            return null;
        }

        $attachment = $attachments[0];
        if ($attachment['type'] !== 'contact') {
            return null;
        }

        $payload = $attachment['payload'] ?? [];
        $vcfInfo = $payload['vcf_info'] ?? '';
        $maxInfo = $payload['max_info'] ?? [];

        $phone = $this->extractPhoneFromVCF($vcfInfo);
        if (!$phone) {
            return null;
        }

        $result = [
            'phone_number' => $phone,
            'first_name'   => '',
            'last_name'    => '',
            'full_name'    => '',
            'user_id'      => null,
        ];

        if (isset($maxInfo['first_name'])) $result['first_name'] = $maxInfo['first_name'];
        if (isset($maxInfo['last_name']))  $result['last_name']  = $maxInfo['last_name'];
        if (isset($maxInfo['name']))       $result['full_name']  = $maxInfo['name'];
        if (isset($maxInfo['user_id']))    $result['user_id']    = $maxInfo['user_id'];

        return $result;
    }

    private function getAttachmentsFromMessage(array $message): array
    {
        if (isset($message['message']['body']['attachments'])) {
            return $message['message']['body']['attachments'];
        }

        if (isset($message['body']['attachments'])) {
            return $message['body']['attachments'];
        }

        return [];
    }

    private function extractPhoneFromVCF(string $vcfInfo): ?string
    {
        if (preg_match('/TEL[^:]*:([0-9+]+)/', $vcfInfo, $matches)) {
            $phone = $matches[1];
            if (strpos($phone, '+') !== 0) {
                $phone = '+' . $phone;
            }
            return $phone;
        }
        return null;
    }

    private function validateContactOwnership(array $message, array $contact): bool
    {
        $senderUserId = $this->getSenderUserId($message);
        $maxInfo = $this->getMaxInfoFromMessage($message);

        if (empty($maxInfo)) {
            return false;
        }

        if (!isset($maxInfo['user_id'])) {
            return false;
        }

        return $maxInfo['user_id'] == $senderUserId;
    }

    private function getSenderUserId(array $message): ?string
    {
        if (isset($message['message']['sender']['user_id'])) {
            return (string)$message['message']['sender']['user_id'];
        }

        if (isset($message['sender']['user_id'])) {
            return (string)$message['sender']['user_id'];
        }

        return null;
    }

    private function getMaxInfoFromMessage(array $message): array
    {
        if (isset($message['message']['body']['attachments'][0]['payload']['max_info'])) {
            return $message['message']['body']['attachments'][0]['payload']['max_info'];
        }

        if (isset($message['body']['attachments'][0]['payload']['max_info'])) {
            return $message['body']['attachments'][0]['payload']['max_info'];
        }

        return [];
    }

    private function sendOwnershipError(string $chatId): void
    {
        $errorMessage = "❌ Ошибка авторизации: вы поделились контактом другого пользователя.\n\n";
        $errorMessage .= "Пожалуйста, поделитесь СВОИМ контактом, нажав на кнопку \"Переслать\" и выбрав свой контакт из списка.";
        $this->maxBot->sendMessage((int)$chatId, $errorMessage);
        $this->logger->warning("Contact ownership validation failed for chat: {$chatId}");
    }

    private function handleEmployeeRegistration(string $phone, string $chatId): void
    {
        $this->logger->info("Employee registration for chat: {$chatId}, phone: {$phone}");

        $this->stateFileHandler->setStateField($chatId, 'role', 'employee');

        $data = $this->okDeskHelper->findUserByPhone($phone);
        if ($data) {
            $companyId = $data['company_id'] ?? null;
            $contactId = $data['id'] ?? null;
            $this->stateFileHandler->setStateField($chatId, 'companyId', $companyId);
            $this->stateFileHandler->setStateField($chatId, 'contactId', $contactId);
            $this->logger->info("Employee found in OkDesk: {$contactId} for chat: {$chatId}");
        } else {
            $this->logger->warning("Employee not found in OkDesk for phone: {$phone}, chat: {$chatId}");
        }

        $this->stateManagerHandler->processState($chatId, State::Employee_Greeting);
    }

    private function handleExternalUserRegistration(
        string $phone,
        string $chatId,
        string $currentState,
        array $contact
    ): void {
        $this->logger->info("External user registration for chat: {$chatId}, phone: {$phone}");

        $this->stateFileHandler->setStateField($chatId, 'role', 'contact');

        if ($currentState !== State::Register->value) {
            $this->logger->info("User not in register state, skipping registration for chat: {$chatId}");
            return;
        }

        $data = $this->okDeskHelper->findUserByPhone($phone);
        if (!$data) {
            $firstName = $contact['first_name'] ?? '';
            $lastName  = $contact['last_name'] ?? '';
            $phoneNumber = $contact['phone_number'] ?? $phone;
            $data = $this->okDeskHelper->createNewUser([], $phoneNumber, $firstName, $lastName);
            $this->logger->info("New user created in OkDesk for chat: {$chatId}");
        }

        if ($data) {
            $companyId = $data['company_id'] ?? null;
            $contactId = $data['id'] ?? null;
            $this->stateFileHandler->setStateField($chatId, 'companyId', $companyId);
            $this->stateFileHandler->setStateField($chatId, 'contactId', $contactId);
            $this->logger->info("User registered: {$contactId} for chat: {$chatId}");

            $this->stateManagerHandler->processState($chatId, State::Employee_Greeting);
        } else {
            $this->logger->error("Failed to create user for chat: {$chatId}, phone: {$phone}");
            $this->maxBot->sendMessage((int)$chatId, "❌ Не удалось зарегистрировать пользователя. Попробуйте позже.");
        }
    }

    /**
     * Публичный метод для обратной совместимости.
     */
    public function handleUserRegistration(string $phoneNumber, string $chatId, string $currentState, array $contact): bool
    {
        $this->logger->info("User registration called for chat: {$chatId}, phone: {$phoneNumber}");

        if ($currentState !== State::Register->value) {
            $this->logger->info("User not in register state, skipping registration for chat: {$chatId}");
            return false;
        }

        $data = $this->okDeskHelper->findUserByPhone($phoneNumber);
        if (!$data) {
            $firstName = $contact['first_name'] ?? '';
            $lastName  = $contact['last_name'] ?? '';
            $phone     = $contact['phone_number'] ?? $phoneNumber;
            $data = $this->okDeskHelper->createNewUser([], $phone, $firstName, $lastName);
            $this->logger->info("New user created for chat: {$chatId}");
        }

        if ($data) {
            $companyId = $data['company_id'] ?? null;
            $contactId = $data['id'] ?? null;
            $this->stateFileHandler->setStateField($chatId, 'companyId', $companyId);
            $this->stateFileHandler->setStateField($chatId, 'contactId', $contactId);
            $this->stateManagerHandler->processState($chatId, State::Employee_Greeting);
            $this->logger->info("User registration successful for chat: {$chatId}");
            return true;
        }

        $this->logger->error("User registration failed for chat: {$chatId}");
        return false;
    }

    public function handleUserByPhoneNumber(string $phoneNumber, string $chatId): void
    {
        $this->logger->info("Handle user by phone for chat: {$chatId}, phone: {$phoneNumber}");

        $data = $this->okDeskHelper->findUserByPhone($phoneNumber);
        if ($data) {
            $companyId = $data['company_id'] ?? null;
            $contactId = $data['id'] ?? null;
            $this->stateFileHandler->setStateField($chatId, 'companyId', $companyId);
            $this->stateFileHandler->setStateField($chatId, 'contactId', $contactId);
            $this->logger->info("User found: {$contactId} for chat: {$chatId}");
        } else {
            $this->logger->warning("User not found for phone: {$phoneNumber}, chat: {$chatId}");
        }
    }

    public function checkUserByPhoneNumber(string $phoneNumber)
    {
        return $this->okDeskHelper->findUserByPhone($phoneNumber);
    }

    public function isEmployee(string $phoneNumber): bool
    {
        return $this->armHelper->findWorkerByPhone($phoneNumber);
    }

    public function getUserRole(string $chatId): string
    {
        $role = $this->stateFileHandler->getStateField($chatId, 'role');
        if ($role) {
            return $role;
        }
        return 'unknown';
    }

    public function isUserRegistered(string $chatId): bool
    {
        $contactId = $this->stateFileHandler->getStateField($chatId, 'contactId');
        return !empty($contactId);
    }

    public function getContactId(string $chatId): ?string
    {
        return $this->stateFileHandler->getStateField($chatId, 'contactId');
    }

    public function getCompanyId(string $chatId): ?string
    {
        return $this->stateFileHandler->getStateField($chatId, 'companyId');
    }

    public function getPhoneNumber(string $chatId): ?string
    {
        return $this->stateFileHandler->getStateField($chatId, 'phone_number');
    }

    public function clearRegistrationData(string $chatId): void
    {
        $this->stateFileHandler->clearState($chatId);
        $this->logger->info("Registration data cleared for chat: {$chatId}");
    }

    public function requestContact(string $chatId, string $message = null): void
    {
        if ($message === null) {
            $text = 'Для регистрации нам нужен ваш номер телефона. Нажмите кнопку ниже 📱';
        } else {
            $text = $message;
        }
        $this->maxBot->requestContact((int)$chatId, $text);
        $this->logger->info("Contact requested for chat: {$chatId}");
    }
}