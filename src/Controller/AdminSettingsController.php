<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Settings;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin-changeable runtime configuration (quota defaults, registration
 * mode). Gated by RequireAdmin at the route-group level.
 */
final class AdminSettingsController
{
    public function __construct(
        private readonly View $view,
        private readonly Settings $settings,
        private readonly Flash $flash,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'admin/settings', [
            'values' => $this->settings->allEffective(),
        ]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $mode = (string) ($body['registration_mode'] ?? Settings::REGISTRATION_MODE_EMAIL);
        if (!in_array($mode, [Settings::REGISTRATION_MODE_EMAIL, Settings::REGISTRATION_MODE_ADMIN_APPROVAL], true)) {
            $mode = Settings::REGISTRATION_MODE_EMAIL;
        }
        $this->settings->set('registration.mode', $mode);

        $this->setIntIfValid($body, 'token_ttl_seconds', 'registration.token_ttl_seconds', min: 60);
        $this->setMegabytesIfValid($body, 'quota_storage_user', 'quota.storage.user');
        $this->setMegabytesIfValid($body, 'quota_storage_ai_user', 'quota.storage.ai_user');
        $this->setMegabytesIfValid($body, 'quota_storage_manager', 'quota.storage.manager');
        $this->setIntIfValid($body, 'quota_ai_ai_user', 'quota.ai.ai_user', min: 0);
        $this->setIntIfValid($body, 'quota_ai_manager', 'quota.ai.manager', min: 0);

        $this->flash->add('success', t('admin.settings_saved'));
        return $response->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setIntIfValid(array $body, string $field, string $settingKey, int $min): void
    {
        $raw = trim((string) ($body[$field] ?? ''));
        if ($raw !== '' && is_numeric($raw) && (int) $raw >= $min) {
            $this->settings->set($settingKey, (string) (int) $raw);
        }
    }

    /**
     * Form takes megabytes, stored as bytes.
     *
     * @param array<string, mixed> $body
     */
    private function setMegabytesIfValid(array $body, string $field, string $settingKey): void
    {
        $raw = trim((string) ($body[$field] ?? ''));
        if ($raw !== '' && is_numeric($raw) && (float) $raw >= 0) {
            $this->settings->set($settingKey, (string) (int) round((float) $raw * 1024 * 1024));
        }
    }
}
