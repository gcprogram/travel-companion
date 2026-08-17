<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PoiDiscoveryService;
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
            'searchableCategories' => PoiDiscoveryService::searchableCategories(),
            // Never the decrypted key itself - just whether one is stored,
            // so the field can render as blank-but-configured rather than
            // ever putting the secret back into the page/browser.
            'placesApiKeyConfigured' => $this->settings->getSecret('google.places_api_key') !== null,
            'aiApiKeyConfigured' => $this->settings->getSecret('ai.api_key') !== null,
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
        $this->setIntIfValid($body, 'poi_search_radius', 'poi.search_radius_meters', min: 50);
        $this->setIntIfValid($body, 'poi_photo_match', 'poi.photo_match_meters', min: 10);

        // Unchecking everything would silently disable discovery entirely,
        // so an empty selection keeps the previous value rather than saving
        // a state the UI gives no hint about.
        $categories = $body['poi_categories'] ?? null;
        if (is_array($categories)) {
            $valid = array_values(array_intersect(
                array_map(strval(...), $categories),
                PoiDiscoveryService::searchableCategories(),
            ));
            if ($valid !== []) {
                $this->settings->set('poi.categories', implode(',', $valid));
            }
        }

        // Blank submitted = leave the stored key untouched (the field never
        // shows the real value, so "blank" can't mean "the admin wants to
        // clear it" - that's what the separate checkbox is for).
        $placesApiKey = trim((string) ($body['google_places_api_key'] ?? ''));
        if (!empty($body['google_places_api_key_clear'])) {
            $this->settings->setSecret('google.places_api_key', null);
        } elseif ($placesApiKey !== '') {
            $this->settings->setSecret('google.places_api_key', $placesApiKey);
        }

        $aiBaseUrl = trim((string) ($body['ai_base_url'] ?? ''));
        if ($aiBaseUrl !== '') {
            $this->settings->set('ai.base_url', $aiBaseUrl);
        }
        $aiModel = trim((string) ($body['ai_model'] ?? ''));
        if ($aiModel !== '') {
            $this->settings->set('ai.model', $aiModel);
        }
        $aiApiKey = trim((string) ($body['ai_api_key'] ?? ''));
        if (!empty($body['ai_api_key_clear'])) {
            $this->settings->setSecret('ai.api_key', null);
        } elseif ($aiApiKey !== '') {
            $this->settings->setSecret('ai.api_key', $aiApiKey);
        }

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
