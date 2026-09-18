<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use App\Application\Helpers\ArmHelper;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\Helpers\QrCodeHelper;
use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\StateManagerHandler;
use App\Application\Handlers\BotStartedHandler;
use App\Application\Handlers\CommandHandler;
use App\Application\Handlers\CommentHandler;
use App\Application\Handlers\FileAttachmentHandler;
use App\Application\Handlers\FileConverter;
use App\Application\Handlers\FinishRequestHandler;
use App\Application\Handlers\InitialRegistrationHandler;
use App\Application\Handlers\IssueCloseHandler;
use App\Application\Handlers\MenuTransitionHandler;
use App\Application\Handlers\RegistrationHandler;
use App\Application\Handlers\UnknownTextHandler;
use App\Application\Handlers\UnsupportedTypeHandler;
use App\Application\State\State;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([

        // ============================================================
        // Логгер
        // ============================================================
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);

            $loggerSettings = $settings->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },

        // ============================================================
        // Базовые хелперы
        // ============================================================
        MaxBotHelper::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);
            $maxBotSettings = $settings->get('maxbot');

            return new MaxBotHelper(
                $maxBotSettings['token'],
                $maxBotSettings['config'] ?? []
            );
        },

        OkDeskHelper::class => function (ContainerInterface $c) {
            return new OkDeskHelper($c->get(LoggerInterface::class));
        },

        ArmHelper::class => function (ContainerInterface $c) {
            return new ArmHelper($c->get(LoggerInterface::class));
        },

        QrCodeHelper::class => function (ContainerInterface $c) {
            return new QrCodeHelper(
                $c->get(LoggerInterface::class),
                $c->get(MaxBotHelper::class)
            );
        },

        // ============================================================
        // Базовые хендлеры состояния
        // ============================================================
        StateFileHandler::class => function () {
            return new StateFileHandler();
        },

        // ============================================================
        // Строковые ключи
        // ============================================================

        'maxBot' => function (ContainerInterface $c) {
            return $c->get(MaxBotHelper::class);
        },

        'okdeskHelper' => function (ContainerInterface $c) {
            return $c->get(OkDeskHelper::class);
        },

        'armHelper' => function (ContainerInterface $c) {
            return $c->get(ArmHelper::class);
        },

        'qrCodeHelper' => function (ContainerInterface $c) {
            return $c->get(QrCodeHelper::class);
        },

        'stateFileHandler' => function (ContainerInterface $c) {
            return $c->get(StateFileHandler::class);
        },

        'menuCreateAction' => function (ContainerInterface $c) {
            return new MenuCreateAction($c->get(StateFileHandler::class));
        },

        'stateManagerHandler' => function (ContainerInterface $c) {
            return new StateManagerHandler(
                $c->get(StateFileHandler::class),
                $c->get(LoggerInterface::class),
                $c->get('menuCreateAction'),
                $c->get(MaxBotHelper::class)
            );
        },

        // ============================================================
        // Хендлеры рефакторинга
        // ============================================================

        'fileConverter' => function () {
            return new FileConverter();
        },

        'botStartedHandler' => function (ContainerInterface $c) {
            return new BotStartedHandler(
                $c->get(StateFileHandler::class),
                $c->get('stateManagerHandler'),
                $c->get(MaxBotHelper::class)
            );
        },

        'initialRegistrationHandler' => function (ContainerInterface $c) {
            return new InitialRegistrationHandler(
                $c->get(StateFileHandler::class)
            );
        },

        'unknownTextHandler' => function (ContainerInterface $c) {
            return new UnknownTextHandler(
                $c->get(MaxBotHelper::class)
            );
        },

        'finishRequestHandler' => function (ContainerInterface $c) {
            return new FinishRequestHandler(
                $c->get('menuCreateAction'),
                $c->get(MaxBotHelper::class)
            );
        },

        'commandHandler' => function (ContainerInterface $c) {
            return new CommandHandler(
                $c->get(LoggerInterface::class),
                $c->get(StateFileHandler::class),
                $c->get('stateManagerHandler'),
                $c->get('menuCreateAction'),
                $c->get(MaxBotHelper::class),
                $c->get(OkDeskHelper::class)
            );
        },

        'issueCloseHandler' => function (ContainerInterface $c) {
            return new IssueCloseHandler(
                $c->get(LoggerInterface::class),
                $c->get(StateFileHandler::class),
                $c->get('menuCreateAction'),
                $c->get(MaxBotHelper::class),
                $c->get(OkDeskHelper::class)
            );
        },

        'unsupportedTypeHandler' => function (ContainerInterface $c) {
            $commandStates = [
                State::Main_Command->value,
                State::Create_Command->value,
                State::Close_Command->value,
                State::Delete_Command->value,
            ];

            return new UnsupportedTypeHandler(
                $c->get(StateFileHandler::class),
                $c->get('menuCreateAction'),
                $c->get(MaxBotHelper::class),
                $c->get(QrCodeHelper::class),
                $c->get(OkDeskHelper::class),
                $commandStates
            );
        },

        'fileAttachmentHandler' => function (ContainerInterface $c) {
            return new FileAttachmentHandler(
                $c->get(LoggerInterface::class),
                $c->get(StateFileHandler::class),
                $c->get('menuCreateAction'),
                $c->get(MaxBotHelper::class),
                $c->get(OkDeskHelper::class),
                $c->get('fileConverter')
            );
        },
        
        'fileHandler' => function (ContainerInterface $c) {
            return new \App\Application\Handlers\FileHandler(
                $c->get(LoggerInterface::class),
                $c->get(StateFileHandler::class),
                $c->get(MaxBotHelper::class)
            );
        },

        'menuTransitionHandler' => function (ContainerInterface $c) {
            $commandStates = [
                State::Main_Command->value,
                State::Create_Command->value,
                State::Close_Command->value,
                State::Delete_Command->value,
            ];

            return new MenuTransitionHandler(
                $c->get(LoggerInterface::class),
                $c->get(StateFileHandler::class),
                $c->get('menuCreateAction'),
                $c->get(MaxBotHelper::class),
                $c->get(OkDeskHelper::class),
                $c->get('fileConverter'),
                $commandStates
            );
        },

        'commentHandler' => function (ContainerInterface $c) {
            return new CommentHandler(
                $c->get(LoggerInterface::class),
                $c->get(OkDeskHelper::class),
                $c->get(StateFileHandler::class),
            );
        },

        'registrationHandler' => function (ContainerInterface $c) {
            return new RegistrationHandler(
                $c->get(LoggerInterface::class),
                $c->get(OkDeskHelper::class),
                $c->get(StateFileHandler::class),
                $c->get(ArmHelper::class),
                $c->get(MaxBotHelper::class),
                $c->get('stateManagerHandler'),
                $c->get('menuCreateAction')
            );
        },

        // ============================================================
        // Фасад
        // ============================================================
        \App\Application\Actions\Hook\ListHooksAction::class => function (ContainerInterface $c) {
            return new \App\Application\Actions\Hook\ListHooksAction(
                $c->get(LoggerInterface::class),
                $c,
                $c->get(MaxBotHelper::class),
                $c->get('menuCreateAction'),
                $c->get(StateFileHandler::class),
                $c->get(OkDeskHelper::class),
                $c->get('stateManagerHandler'),
                $c->get(QrCodeHelper::class)
            );
        },

    ]);
};
