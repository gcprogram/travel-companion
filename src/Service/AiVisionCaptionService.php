<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generates a short photo/video description via a vision-capable chat
 * model (the 'vision' AI slot, AiProviderResolver) - the button-triggered
 * feature Stefan asked for after the AI MediaAnalyzer metadata import: its
 * offline BLIP captions are a reasonable starting point, but he expects
 * this app's own online vision model to do noticeably better, and wants it
 * able to overwrite an EXIF-imported caption on demand rather than only
 * ever accepting what BLIP produced (PhotoController::caption()/
 * VideoController::caption(), caption_source='vision_ai' vs 'exif_import').
 *
 * Same OpenAI-compatible chat-completions dialect as every other AI
 * feature in this app (image content sent as a base64 data URL in the
 * message, the standard way OpenAI/most compatible providers accept
 * inline images) - no new provider dialect needed, but the assigned model
 * does need to actually support image input, which is why this has its
 * own slot rather than reusing 'main' (not every chat model does).
 */
final class AiVisionCaptionService
{
    public function __construct(private readonly AiProviderResolver $resolver)
    {
    }

    /**
     * @param string $imageBytes raw bytes of a small-ish derivative (this
     *        app's own 'web'/poster variant, never a multi-MB original) -
     *        sent inline as base64, so a large image directly inflates the
     *        request body and token cost.
     */
    public function describe(string $imageBytes, string $mimeType): ?string
    {
        $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageBytes);

        foreach ($this->resolver->resolveChain('vision') as $provider) {
            $result = $this->callProvider($provider, $dataUrl);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array{baseUrl: string, model: string, apiKey: string} $provider
     */
    private function callProvider(array $provider, string $dataUrl): ?string
    {
        $ch = curl_init($provider['baseUrl'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $provider['apiKey'],
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $provider['model'],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' =>
                                'Describe this travel photo in one or two concise, natural sentences '
                                . 'for a travel diary caption - what is shown, and anything notable about '
                                . 'the place, scene, or people. No markdown, no quotation marks, no preamble '
                                . 'like "This image shows" - just the description itself.'],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                        ],
                    ],
                ],
                // Room for a reasoning vision model's internal thinking
                // before the answer - same issue observed live against a
                // real reasoning model in AdminAiProviderController::test().
                'max_tokens' => 500,
                'temperature' => 0.4,
            ], JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return null;
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        return $this->clean($content);
    }

    private function clean(string $content): string
    {
        $cleaned = trim($content);
        $cleaned = trim(preg_replace('/^```[a-z]*|```$/mi', '', $cleaned) ?? $cleaned);
        $cleaned = trim($cleaned, "\"'“”„ \t\n\r");
        return mb_substr($cleaned, 0, 1000);
    }
}
