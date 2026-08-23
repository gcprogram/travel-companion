<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AiProviderConfigRepository;
use App\Service\Settings;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Manages saved AI provider profiles (ai_provider_configs) from
 * /admin/settings: add/delete, plus the "fetch available models" step
 * (GCToolkit-android's own three-step provider->key->models UX, ported
 * here). Gated by RequireAdmin at the route-group level, same as
 * AdminSettingsController.
 */
final class AdminAiProviderController
{
    public function __construct(
        private readonly AiProviderConfigRepository $providers,
        private readonly Settings $settings,
        private readonly Flash $flash,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $label = trim((string) ($body['label'] ?? ''));
        $provider = trim((string) ($body['provider'] ?? ''));
        $baseUrl = trim((string) ($body['base_url'] ?? ''));
        $model = trim((string) ($body['model'] ?? ''));
        $apiKey = trim((string) ($body['api_key'] ?? ''));

        if ($label === '' || $baseUrl === '' || $model === '' || $apiKey === '') {
            $this->flash->add('error', t('admin.settings_ai_provider_add_error'));
            return $this->redirect($response);
        }

        $id = $this->providers->create($label, $provider !== '' ? $provider : 'custom', $baseUrl, $model);
        $this->settings->setSecret('ai.provider.' . $id . '.api_key', $apiKey);

        $this->flash->add('success', t('admin.settings_ai_provider_added', ['label' => $label]));
        return $this->redirect($response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        // A slot pointing at the config being deleted must not keep
        // pointing at a now-nonexistent id - AiProviderResolver would just
        // find nothing and return null (same as "off"), which is the
        // right degrade, but clearing the assignment explicitly means the
        // settings page doesn't keep showing a dropdown selection for a
        // config that's gone.
        if ($this->settings->getInt('ai.slot.main') === $id) {
            $this->settings->set('ai.slot.main', '0');
        }

        $this->settings->setSecret('ai.provider.' . $id . '.api_key', null);
        $this->providers->delete($id);

        $this->flash->add('success', t('admin.settings_ai_provider_deleted'));
        return $this->redirect($response);
    }

    /**
     * Called via fetch() from the settings page while adding a new
     * provider, before it's saved - takes the base URL/key straight from
     * the form fields as currently typed. GET {base_url}/models with a
     * Bearer token, same OpenAI-compatible dialect the actual chat calls
     * use (AiSummaryService/AiTripMetaService) - this app doesn't speak
     * Anthropic's/Google's native dialects yet, see AiProviderPresets.
     */
    public function fetchModels(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $baseUrl = rtrim(trim((string) ($body['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($body['api_key'] ?? ''));

        if ($baseUrl === '' || $apiKey === '') {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_fetch_missing')], 422);
        }

        $ch = curl_init($baseUrl . '/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'User-Agent: travel-companion (AI provider setup)',
            ],
        ]);
        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return $this->json($response, ['ok' => false, 'error' => $error], 502);
        }
        if ($status !== 200) {
            return $this->json($response, [
                'ok' => false,
                'error' => t('admin.settings_ai_fetch_http_error', ['status' => (string) $status]),
            ], 502);
        }

        try {
            $data = json_decode((string) $responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_fetch_bad_response')], 502);
        }

        $entries = $data['data'] ?? null;
        if (!is_array($entries)) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_fetch_bad_response')], 502);
        }

        $models = [];
        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $models[] = $id;
            }
        }
        $models = array_values(array_unique($models));
        sort($models);

        return $this->json($response, ['ok' => true, 'models' => $models], 200);
    }

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(ResponseInterface $response, array $data, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
