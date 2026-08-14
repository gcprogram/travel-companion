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
    $app->addRoutingMiddleware();

    // Inside out: Locale -> Session -> Auth (load user) -> Body-Parsing -> CSRF.
    // Body-parsing must run before CSRF: PHP only auto-populates $_POST (and
    // therefore getParsedBody()) for form-urlencoded/multipart requests at
    // request-creation time, before any middleware runs at all. A
    // fetch()-with-JSON-body request (google-timeline-import.js,
    // track-folder-scan.js) has no $_POST equivalent - its body only becomes
    // visible via getParsedBody() once Slim's JSON body-parsing middleware
    // has actually run. With body-parsing added (and therefore executing)
    // after CSRF, every JSON POST used to see a null parsed body and fail
    // CSRF unconditionally, regardless of payload size or point count.
    $app->add(CsrfMiddleware::class);
    $app->addBodyParsingMiddleware();
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
