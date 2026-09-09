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
 * Speaks the OpenAI-compatible chat-completions dialect (image content as
 * a base64 data URL) for most providers, same as every other AI feature in
 * this app - but branches to GoogleGeminiClient's native dialect for a
 * 'google' provider config, since that's the one that actually needs
 * proper vision support as a genuinely independent fallback (see
 * PhotoCaptionHandler). Either way, the assigned model does need to
 * actually support image input, which is why this has its own slot rather
 * than reusing 'main' (not every chat model does).
 */
final class AiVisionCaptionService
{
    public function __construct(
        private readonly AiProviderResolver $resolver,
        private readonly GoogleGeminiClient $gemini,
    ) {
    }

    /**
     * @param string $imageBytes raw bytes of a small-ish derivative (this
     *        app's own 'web'/poster variant, never a multi-MB original) -
     *        sent inline as base64, so a large image directly inflates the
     *        request body and token cost.
     * @param ?string $peopleNotes the trip's own `people_notes` field
     *        ("Name=description" per line, e.g. "Christin=woman with
     *        purple hair") - when given, the model is asked to name
     *        anyone it can confidently match instead of just saying
     *        "a person". Never persisted here, never shown to viewers -
     *        purely a per-call prompt addition.
     * @param ?string $address this photo's own already-known, reverse-
     *        geocoded location (`photos.ai_address`) - when given, the
     *        model is told to use it directly rather than guess a broader
     *        location from indirect visual cues (Stefan's real example: the
     *        model wrote "somewhere in Germany" from license plates and
     *        building style in shot, when the exact city was already known
     *        from GPS).
     * @param ?string $nearbyPoiName name of a sight/geocache this photo is
     *        already assigned to (PoiMediaRepository, existing ~150m
     *        match), if any - lets the model mention it by name instead of
     *        describing it generically.
     */
    public function describe(
        string $imageBytes,
        string $mimeType,
        ?string $peopleNotes = null,
        ?string $address = null,
        ?string $nearbyPoiName = null,
    ): ?string {
        foreach ($this->resolver->resolveChain('vision') as $provider) {
            $result = $this->callProvider($provider, $imageBytes, $mimeType, $peopleNotes, $address, $nearbyPoiName);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Same as describe(), but calls exactly ONE given provider instead of
     * blindly trying the whole chain - used by PhotoCaptionHandler, which
     * needs to know precisely which provider succeeded/failed to drive its
     * own rate-limit/backup-model bookkeeping (AiRateLimitRepository).
     * describe() itself is untouched and keeps its whole-chain behaviour
     * for the manual single-photo button.
     *
     * @param array{baseUrl: string, model: string, apiKey: string, provider: string} $provider
     */
    public function describeWith(
        array $provider,
        string $imageBytes,
        string $mimeType,
        ?string $peopleNotes = null,
        ?string $address = null,
        ?string $nearbyPoiName = null,
    ): ?string {
        return $this->callProvider($provider, $imageBytes, $mimeType, $peopleNotes, $address, $nearbyPoiName);
    }

    /**
     * @param array{baseUrl: string, model: string, apiKey: string, provider?: string} $provider
     */
    private function callProvider(
        array $provider,
        string $imageBytes,
        string $mimeType,
        ?string $peopleNotes,
        ?string $address = null,
        ?string $nearbyPoiName = null,
    ): ?string {
        $instruction = 'Describe this travel photo in one or two concise, natural sentences '
            . 'for a travel diary caption - what is shown, and anything notable about '
            . 'the place, scene, or people. No markdown, no quotation marks, no preamble '
            . 'like "This image shows" - just the description itself.';
        if ($address !== null && trim($address) !== '') {
            $instruction .= ' This photo\'s known location is: ' . trim($address) . '.';
            if ($nearbyPoiName !== null && trim($nearbyPoiName) !== '') {
                $instruction .= ' It was taken near/at the sight or geocache "' . trim($nearbyPoiName) . '".';
            }
            $instruction .= ' State this specific place directly if relevant to the caption - never guess '
                . 'a broader or different location from indirect visual cues (e.g. license plates, '
                . 'architecture style) when the exact place is already given above.';
        } elseif ($nearbyPoiName !== null && trim($nearbyPoiName) !== '') {
            $instruction .= ' This photo was taken near/at the sight or geocache "' . trim($nearbyPoiName) . '" - '
                . 'mention it by name if relevant to the caption.';
        }
        if ($peopleNotes !== null && trim($peopleNotes) !== '') {
            $instruction .= ' The traveller has described the people who might appear in their photos '
                . "(one \"Name=description\" per line):\n" . trim($peopleNotes)
                . "\nIf you can confidently match someone in THIS photo to one of these descriptions, "
                . 'name them by their given name instead of writing "a person"/"a woman"/etc. '
                . "Never guess a name you're not reasonably confident about, and never mention anyone "
                . 'whose description does not match what is actually visible.';
        }

        if (($provider['provider'] ?? '') === 'google') {
            $result = $this->gemini->describeImage(
                $provider['baseUrl'],
                $provider['model'],
                $provider['apiKey'],
                $imageBytes,
                $mimeType,
                $instruction,
            );
            return $result !== null ? $this->clean($result) : null;
        }

        $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageBytes);
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
                            ['type' => 'text', 'text' => $instruction],
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
