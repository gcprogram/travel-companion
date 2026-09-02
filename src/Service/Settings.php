<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;
use App\Support\Env;

/**
 * Admin-changeable runtime configuration, DB-backed with hardcoded
 * defaults. Unlike Env (deploy-time, file-only), these can be edited from
 * the admin UI without server access. Reads are cached per request.
 *
 * Note: always resolve values inside services at call time, never in
 * container factories - the compiled DI container would freeze them.
 *
 * getSecret()/setSecret() store an API-key-shaped value encrypted at rest
 * (sodium_crypto_secretbox) using APP_KEY (.env, never in the DB) as the
 * encryption key - the documented decision for KI-provider keys in
 * CLAUDE.md, first actually used here for the Google Places API key. A row
 * in the settings table with an empty value or a value that fails to
 * decrypt (wrong/rotated APP_KEY) is treated as "not configured" rather
 * than throwing, since a broken key is cosmetically identical to a missing
 * one from every caller's perspective.
 */
final class Settings
{
    public const REGISTRATION_MODE_EMAIL = 'email';
    public const REGISTRATION_MODE_ADMIN_APPROVAL = 'admin_approval';

    /** @var array<string, string> */
    private const DEFAULTS = [
        // Storage quota per role, in bytes. Admins are uncapped (no key).
        'quota.storage.user' => '52428800',        // 50 MB
        'quota.storage.ai_user' => '52428800',     // 50 MB
        'quota.storage.manager' => '524288000',    // 500 MB
        // AI token budget per month (enforced once phase 5 lands).
        'quota.ai.ai_user' => '200000',
        'quota.ai.manager' => '1000000',
        // 'email': confirming the address activates the account.
        // 'admin_approval': confirmed accounts additionally wait for an admin.
        'registration.mode' => self::REGISTRATION_MODE_EMAIL,
        // Confirmation-link validity. Kept short by design (an unconfirmed
        // registration counts as a failed attempt for IP blocking).
        'registration.token_ttl_seconds' => '300',
        // How far from the route POI discovery looks (PoiDiscoveryService).
        // Defaults per trip; the search form on the map page can override it
        // for a single run.
        'poi.search_radius_meters' => '550',
        // How close a geotagged photo/video must be to a POI to count as
        // "taken there" (PoiAssignmentService). Distinct from the search
        // radius above: this one decides what the "remove POIs without
        // photos" action considers unphotographed, so too strict a value
        // silently discards places that were actually visited.
        'poi.photo_match_meters' => '150',
        // How far a found/DNF geocache (matched via GPX/field-notes by GC
        // code + date, not location) may be from the trip's own track and
        // still count as "on this trip" (PoiController::importGpx) -
        // without this, a find from a completely different place visited
        // around the same dates would import too. Larger than
        // poi.photo_match_meters on purpose: geocaches are found on foot
        // but a car may be parked well away from the exact GPS track line.
        'poi.geocache_import_radius_meters' => '2000',
        // Which categories discovery looks for, comma-separated. 'other'
        // is a manual-entry-only category, never searched for.
        'poi.categories' => 'museum,zoo,attraction,viewpoint,monument,sacred_building',
        // Encrypted (see getSecret()/setSecret()) - never a plaintext default.
        'google.places_api_key' => '',
        // Cloud Translation API v2 - sight names in a non-Latin script
        // (PoiNameTranslationService), deliberately its own key/quota
        // rather than sharing the ai.* budget below (Stefan's call - see
        // GoogleTranslateService).
        'google.translate_api_key' => '',
        // Which saved ai_provider_configs row (AiProviderConfigRepository)
        // each named purpose slot uses - '0'/unset means "off", same
        // convention as an empty API key elsewhere. 'main' covers every AI
        // feature so far (day-entry summaries, trip-title/tags); more slots
        // get their own 'ai.slot.<name>' key with no schema change needed.
        // See AiProviderResolver.
        'ai.slot.main' => '0',
        // Reserved for the planned photo-description feature (generating
        // trip/sight/geocache context from what's actually in the images) -
        // only the slot assignment exists so far, no vision feature reads
        // it yet. A vision-capable model needs to be picked deliberately
        // (not every OpenAI-compatible model accepts image input), which is
        // exactly why this is its own slot rather than reusing 'main'.
        'ai.slot.vision' => '0',
        // AiTranslationService's fallback for sight-name translation
        // (PoiNameTranslationService) when GoogleTranslateService is
        // unavailable - unset on Bitpalast/most self-hosted installs since
        // Cloud Translation's v2 API needs a billed Google Cloud project
        // even for its free tier. "A simple model is enough for this"
        // (Stefan) - deliberately its own slot so a cheap/fast model can be
        // picked here without affecting 'main'.
        'ai.slot.translate' => '0',
        // Output token ceiling for the trip-overview and day-description
        // generators (AiTripDescriptionService/AiDayDescriptionService).
        // Was hardcoded per depth (220-950) and kept cutting a "complete"
        // description off mid-sentence (Stefan's report) - a single
        // generous admin-configurable ceiling instead, since a higher
        // budget only costs money when the model actually uses it, never
        // hurts a short reply.
        'ai.description_max_tokens' => '16000',
        // Track-Player (Stefan's idea, PLAN.md): playback speed thresholds,
        // all read client-side only (trip-map.js/track-player.js) - the
        // server never animates anything, it just hands these numbers to
        // the page like any other config. Real time -> playback time:
        // - gap < 1 min: seconds of playback per real minute (a ratio, not
        //   a flat pause - dense points already look fine "jumping"
        //   straight to the next one at this pace, Stefan confirmed no
        //   extra smoothing is needed there).
        // - gap 1-29 min: flat seconds held on the point.
        // - gap >= 30 min (flights, ferry crossings) or a detected
        //   stationary dwell (TrackSmoothingService's isPause) without a
        //   photo in it: flat seconds to fly across the WHOLE gap,
        //   regardless of how long it really was.
        'trackplayer.seconds_per_real_minute' => '1',
        'trackplayer.hold_seconds_per_point' => '1',
        'trackplayer.long_gap_seconds' => '1',
        // Elapsed vs. still-upcoming track color during playback -
        // separate from the always-green full/day track line elsewhere on
        // the map (Stefan's ask: those two concepts must not share a
        // color, unlike the pre-existing day-view bug this feature fixed
        // in passing).
        'trackplayer.color_played' => '#c56a3c',
        'trackplayer.color_upcoming' => '#2f6f5e',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    public function get(string $key): string
    {
        $this->cache ??= $this->repository->all();
        return $this->cache[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public function getInt(string $key): int
    {
        return (int) $this->get($key);
    }

    public function getFloat(string $key): float
    {
        return (float) $this->get($key);
    }

    /**
     * Comma-separated setting as a list, blanks dropped.
     *
     * @return list<string>
     */
    public function getList(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($key))), static fn (string $v): bool => $v !== ''));
    }

    public function set(string $key, string $value): void
    {
        $this->repository->set($key, $value);
        $this->cache = null;
    }

    /**
     * @return array<string, string> effective values for every known key
     */
    public function allEffective(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    /**
     * Decrypted value of an encrypted setting, or null if unset/unreadable.
     * Never returns the raw stored ciphertext.
     */
    public function getSecret(string $key): ?string
    {
        $stored = $this->get($key);
        if ($stored === '') {
            return null;
        }
        return $this->decrypt($stored);
    }

    /**
     * @param ?string $value null or '' clears the stored secret
     */
    public function setSecret(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->set($key, '');
            return;
        }
        $this->set($key, $this->encrypt($value));
    }

    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->encryptionKey());
        return base64_encode($nonce . $cipher);
    }

    private function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->encryptionKey());
        return $plain === false ? null : $plain;
    }

    private function encryptionKey(): string
    {
        $encoded = Env::require('APP_KEY');
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'APP_KEY must be a base64-encoded ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . '-byte key (see .env.example).'
            );
        }
        return $key;
    }
}
