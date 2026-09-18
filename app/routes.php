<?php

declare(strict_types=1);

use App\Application\Actions\Hook\ListHooksAction;
use App\Application\Actions\OkDeskCallBack\ListOkDeskCallBackAction;
use App\Application\Actions\QrCode\ProcessQrCodeAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {

    //Application Health check
    $app->get('/health-check', function (Request $request, Response $response): Response {
        $response->getBody()->write(json_encode([
            'version' => $_ENV['APP_VERSION'] ?? 'undefined',
            'bottype' => $_ENV['BOT_MODE'] ?? 'undefined',
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->options('{routes:.+}', function (Request $request, Response $response) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withStatus(200);
    });

    $app->add(function (Request $request, $handler) {
        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    });

    $app->post('/okdesk-callback', ListOkDeskCallBackAction::class);
    $app->post('/hook', ListHooksAction::class);

    $app->post('/api/webapp/qrcode', ProcessQrCodeAction::class);
};
