<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AiProviderConfigRepository;
use App\Service\AiProviderResolver;
use App\Service\GoogleGeminiClient;
use App\Service\Settings;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Manages saved AI provider profiles (ai_provider_configs) from
 * /admin/settings: add/delete, plus the "fetch available models" step
 * (GCToolkit-android's own three-step provider->key->models UX, ported
 * here). Gated by RequireAdmin at the route-group level, same as
 * AdminSettingsController. fetchModels()/test()/testSearch() all branch on
 * the 'google' provider dialect (GoogleGeminiClient) - every other preset
 * still speaks the OpenAI-compatible dialect directly.
 */
final class AdminAiProviderController
{
    public function __construct(
        private readonly AiProviderConfigRepository $providers,
        private readonly Settings $settings,
        private readonly GoogleGeminiClient $gemini,
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
        foreach (AiProviderResolver::KNOWN_SLOTS as $slot) {
            if ($this->settings->getInt('ai.slot.' . $slot) === $id) {
                $this->settings->set('ai.slot.' . $slot, '0');
            }
        }

        $this->settings->setSecret('ai.provider.' . $id . '.api_key', null);
        $this->providers->delete($id);

        $this->flash->add('success', t('admin.settings_ai_provider_deleted'));
        return $this->redirect($response);
    }

    /**
     * "Testen" button next to an already-saved provider (Stefan's ask: a
     * way to check a configured model is actually reachable and produces a
     * real completion, not just that the /models list responds - a model
     * name can be valid there and still fail/rate-limit on an actual chat
     * call). Sends one minimal real chat-completion request using the
     * saved base_url/model/key and reports success + latency, or the
     * specific failure (explicitly calling out a 429 as a rate limit,
     * since that's the case Stefan wants the fallback chain in
     * AiProviderResolver::resolveChain() to route around).
     */
    public function test(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $config = $this->providers->findById($id);
        if ($config === null) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_not_found')], 404);
        }

        $apiKey = $this->settings->getSecret('ai.provider.' . $id . '.api_key');
        if ($apiKey === null) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_no_key')], 422);
        }

        $baseUrl = rtrim((string) $config['base_url'], '/');
        $started = microtime(true);

        if ((string) $config['provider'] === 'google') {
            $result = $this->gemini->generateText($baseUrl, (string) $config['model'], $apiKey, '', 'Reply with only the single word: OK', 0.2, 400, 55);
            $latencyMs = (int) round((microtime(true) - $started) * 1000);
            if ($result === null) {
                return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_empty_response'), 'latencyMs' => $latencyMs], 502);
            }
            return $this->json($response, ['ok' => true, 'latencyMs' => $latencyMs, 'sample' => mb_substr($result, 0, 80)], 200);
        }

        $ch = curl_init($baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Longer than the 25s AiSummaryService/AiTripMetaService use in
            // production - a "reasoning" model can genuinely take longer
            // than that to think through even a trivial prompt (observed:
            // ~13s for a 2-word answer), and a false "broken" report from
            // this button for a model that's merely slow defeats its point.
            // Production calls keep the shorter timeout deliberately -
            // resolveChain()'s fallback already covers a slow/timing-out
            // model there.
            CURLOPT_TIMEOUT => 55,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $config['model'],
                'messages' => [
                    ['role' => 'user', 'content' => 'Reply with only the single word: OK'],
                ],
                // Generous on purpose: a "reasoning" model (e.g. NVIDIA's
                // nemotron line) can spend 200+ tokens on an internal
                // chain-of-thought - returned as its own reasoning/
                // reasoning_content field - before ever emitting the actual
                // answer. A small max_tokens starves that answer entirely
                // (empty content, even though the model is working fine),
                // which would make this test wrongly report a healthy
                // reasoning model as broken.
                'max_tokens' => 400,
            ], JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($body === false) {
            return $this->json($response, ['ok' => false, 'error' => $curlError, 'latencyMs' => $latencyMs], 502);
        }
        if ($status === 429) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_rate_limited'), 'latencyMs' => $latencyMs], 502);
        }
        if ($status !== 200) {
            return $this->json($response, [
                'ok' => false,
                'error' => t('admin.settings_ai_fetch_http_error', ['status' => (string) $status]),
                'latencyMs' => $latencyMs,
            ], 502);
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_fetch_bad_response'), 'latencyMs' => $latencyMs], 502);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_empty_response'), 'latencyMs' => $latencyMs], 502);
        }

        return $this->json($response, [
            'ok' => true,
            'latencyMs' => $latencyMs,
            'sample' => mb_substr(trim($content), 0, 80),
        ], 200);
    }

    /**
     * "Web-Search testen" - only rendered/reachable for a 'google' config
     * (see templates/admin/settings.php): no product feature consumes web
     * search yet, this just proves the grounding tool actually works for
     * the saved key/model before anything is built on top of it. Reports
     * `searched=false` as a distinct (not necessarily broken) outcome - a
     * 200 response with no grounding metadata means the model answered
     * without feeling the need to search, not that the request failed.
     */
    public function testSearch(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $config = $this->providers->findById($id);
        if ($config === null || (string) $config['provider'] !== 'google') {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_not_found')], 404);
        }

        $apiKey = $this->settings->getSecret('ai.provider.' . $id . '.api_key');
        if ($apiKey === null) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_no_key')], 422);
        }

        $baseUrl = rtrim((string) $config['base_url'], '/');
        $started = microtime(true);
        $result = $this->gemini->searchGrounded($baseUrl, (string) $config['model'], $apiKey, 'What is today\'s date?');
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($result['text'] === null) {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_test_empty_response'), 'latencyMs' => $latencyMs], 502);
        }

        return $this->json($response, [
            'ok' => true,
            'latencyMs' => $latencyMs,
            'searched' => $result['searched'],
            'sourceCount' => count($result['sources']),
        ], 200);
    }

    /**
     * Called via fetch() from the settings page while adding a new
     * provider, before it's saved - takes the base URL/key straight from
     * the form fields as currently typed. Branches on the preset picked in
     * the form (Google's native dialect vs. everyone else's OpenAI-
     * compatible GET {base_url}/models with a Bearer token) - see
     * AiProviderPresets.
     */
    public function fetchModels(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $baseUrl = rtrim(trim((string) ($body['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($body['api_key'] ?? ''));
        $provider = trim((string) ($body['provider'] ?? ''));

        if ($baseUrl === '' || $apiKey === '') {
            return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_fetch_missing')], 422);
        }

        if ($provider === 'google') {
            $models = $this->gemini->listModels($baseUrl, $apiKey);
            if ($models === null) {
                return $this->json($response, ['ok' => false, 'error' => t('admin.settings_ai_fetch_bad_response')], 502);
            }
            sort($models);
            return $this->json($response, ['ok' => true, 'models' => $models], 200);
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
