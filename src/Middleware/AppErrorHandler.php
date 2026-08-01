<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use Throwable;

/**
 * Replaces Slim's bare built-in error page - plain text, its "Go Back" link
 * is just history.go(-1), no real navigation - with one that matches the
 * site and always gives a way forward. Registered as the default error
 * handler in config/middleware.php.
 *
 * Runs as part of the error-handling middleware, which wraps everything
 * except SecurityHeadersMiddleware. Since Auth/Session/Locale middleware
 * all run further in (closer to routing) than this, $request->getAttribute
 * ('user') is already populated by the time an exception from routing
 * (404/405) or a controller reaches here.
 */
final class AppErrorHandler
{
    public function __construct(private readonly View $view)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        $key = match (true) {
            $exception instanceof HttpNotFoundException => 'not_found',
            $exception instanceof HttpMethodNotAllowedException => 'method_not_allowed',
            default => 'generic',
        };

        $user = $request->getAttribute('user');
        $response = new Response($status);

        return $this->view->render($response, 'error', [
            'title' => t('error.' . $key . '_title'),
            'message' => t('error.' . $key . '_message'),
            'homeLink' => $user !== null ? '/' : '/login',
        ], status: $status);
    }
}
