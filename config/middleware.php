<?php

declare(strict_types=1);

use App\Middleware\AppErrorHandler;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\LocaleMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\SessionMiddleware;
use App\Support\Env;
use Psr\Log\LoggerInterface;
use Slim\App;

return static function (App $app): void {
    // Order: the last middleware added runs first.
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();

    // Inside out: Locale -> Session -> Auth (load user) -> CSRF
    $app->add(CsrfMiddleware::class);
    $app->add(AuthMiddleware::class);
    $app->add(SessionMiddleware::class);
    $app->add(LocaleMiddleware::class);

    $isDev = Env::get('APP_ENV', 'production') === 'development';
    $errorMiddleware = $app->addErrorMiddleware(
        displayErrorDetails: $isDev,
        logErrors: true,
        logErrorDetails: true,
        logger: $app->getContainer()?->get(LoggerInterface::class),
    );
    $errorMiddleware->getDefaultErrorHandler()->forceContentType('text/html');
    // Replaces Slim's bare default page (its "Go Back" link is just
    // history.go(-1), no real navigation) with one that matches the site
    // and always links somewhere useful (home if logged in, /login if not).
    $errorMiddleware->setDefaultErrorHandler($app->getContainer()?->get(AppErrorHandler::class));

    // Added after addErrorMiddleware() so it wraps it too — security headers
    // must land on error pages (404/403/500), not just successful responses.
    $app->add(SecurityHeadersMiddleware::class);
};
