<?php

declare(strict_types=1);

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SessionMiddleware;
use App\Support\Env;
use Psr\Log\LoggerInterface;
use Slim\App;

return static function (App $app): void {
    // Reihenfolge: zuletzt hinzugefügte Middleware läuft zuerst.
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();

    // Innen nach außen: Session -> Auth (User laden) -> CSRF
    $app->add(CsrfMiddleware::class);
    $app->add(AuthMiddleware::class);
    $app->add(SessionMiddleware::class);

    $isDev = Env::get('APP_ENV', 'production') === 'development';
    $errorMiddleware = $app->addErrorMiddleware(
        displayErrorDetails: $isDev,
        logErrors: true,
        logErrorDetails: true,
        logger: $app->getContainer()?->get(LoggerInterface::class),
    );
    $errorMiddleware->getDefaultErrorHandler()->forceContentType('text/html');
};
