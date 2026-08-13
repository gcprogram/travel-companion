<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use App\Support\ClientIp;
use App\Support\Env;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    private const LOGOUT_BOUNCE_DELAY_SECONDS = 3;

    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly Flash $flash,
    ) {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getAttribute('user') !== null) {
            return $this->redirect($response, '/');
        }
        return $this->view->render($response, 'auth/login');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = (string) ($body['email'] ?? '');
        $ip = ClientIp::from($request);

        if ($this->auth->isLockedOut($ip)) {
            return $this->view->render($response, 'auth/login', [
                'errors' => [t('auth.login.too_many_attempts')],
                'old' => ['email' => $email],
            ], status: 429);
        }

        if ($this->auth->attemptLogin($email, (string) ($body['password'] ?? ''), $ip)) {
            return $this->redirect($response, '/');
        }

        return $this->view->render($response, 'auth/login', [
            'errors' => [t('auth.login.invalid')],
            'old' => ['email' => $email],
        ], status: 422);
    }

    public function showRegister(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getAttribute('user') !== null) {
            return $this->redirect($response, '/');
        }
        if (!Env::bool('REGISTRATION_OPEN', true)) {
            $this->flash->add('info', t('flash.registration_closed'));
            return $this->redirect($response, '/login');
        }
        return $this->view->render($response, 'auth/register');
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $result = $this->auth->register(
            (string) ($body['email'] ?? ''),
            (string) ($body['name'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['password_repeat'] ?? ''),
            ClientIp::from($request),
        );

        if (!$result['ok']) {
            return $this->view->render($response, 'auth/register', [
                'errors' => $result['errors'],
                'old' => ['email' => (string) ($body['email'] ?? ''), 'name' => (string) ($body['name'] ?? '')],
            ], status: 422);
        }

        // No auto-login anymore: same message whether or not the email was
        // already taken, so the response itself never reveals which.
        $this->flash->add('info', t('flash.registration_check_email'));
        return $this->redirect($response, '/login');
    }

    public function confirmEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        if ($token === '') {
            return $this->redirect($response, '/login');
        }

        $result = $this->auth->confirmEmail($token, ClientIp::from($request));

        if (!$result['ok']) {
            $this->flash->add('error', $result['error']);
            return $this->redirect($response, '/login');
        }

        if ($result['loggedIn']) {
            $this->flash->add('success', t('flash.email_confirmed_welcome'));
            return $this->redirect($response, '/');
        }

        $this->flash->add('info', t('flash.email_confirmed_pending_approval'));
        return $this->redirect($response, '/login');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->auth->logout();

        return $this->view->render($response, 'errors/bounce', [
            'message' => t('auth.logged_out'),
            'redirectTo' => '/',
            'headExtra' => '<meta http-equiv="refresh" content="' . self::LOGOUT_BOUNCE_DELAY_SECONDS . ';url=/">',
        ]);
    }

    public function showForgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'auth/forgot');
    }

    public function forgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $this->auth->requestPasswordReset((string) ($body['email'] ?? ''));

        // Always the same response, regardless of whether the address exists.
        $this->flash->add('info', t('flash.password_reset_requested'));
        return $this->redirect($response, '/login');
    }

    public function showReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        if ($token === '') {
            return $this->redirect($response, '/forgot-password');
        }
        return $this->view->render($response, 'auth/reset', ['token' => $token]);
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = (string) ($body['token'] ?? '');

        $result = $this->auth->resetPassword(
            $token,
            (string) ($body['password'] ?? ''),
            (string) ($body['password_repeat'] ?? ''),
        );

        if (!$result['ok']) {
            return $this->view->render($response, 'auth/reset', [
                'errors' => $result['errors'],
                'token' => $token,
            ], status: 422);
        }

        $this->flash->add('success', t('flash.password_changed'));
        return $this->redirect($response, '/login');
    }

    private function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
