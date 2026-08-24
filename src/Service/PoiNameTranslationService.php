<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PoiNameTranslationRepository;

/**
 * Translates a non-Latin-script sight name (Thai, Cyrillic, CJK, Arabic, ...)
 * into English - see PoiDiscoveryService for why this exists: an OSM
 * element without a name:de/name:en tag would otherwise show up in the
 * sights list in a script most travellers can't read at all, which used to
 * mean silently keeping the unreadable local name (never discarding the
 * sight itself - that's still true here, just no longer the ONLY option).
 *
 * Two backends, tried in order:
 * 1. GoogleTranslateService - kept as the primary path for whoever has it
 *    configured (Stefan's original call: its own quota, separate from the
 *    "real" AI features' budget, broad script coverage).
 * 2. AiTranslationService (the 'translate' AI slot) - the fallback Stefan
 *    asked for once it turned out Cloud Translation's key-based v2 API
 *    needs a *billed* Google Cloud project even for the "free" tier, which
 *    neither he nor (presumably) most self-hosters of this app have. Also
 *    used directly whenever no Google key is configured at all.
 *
 * Best-effort: returns null only once both backends have failed/are
 * unavailable (no key/slot configured, network, bad response) rather than
 * throwing - the caller already knows to fall back to the raw local name,
 * same "cosmetic gap, never worth failing over" reasoning as everywhere
 * else this pattern is used.
 */
final class PoiNameTranslationService
{
    public function __construct(
        private readonly GoogleTranslateService $googleTranslate,
        private readonly AiTranslationService $aiTranslate,
        private readonly PoiNameTranslationRepository $cache,
    ) {
    }

    public function translate(string $localName): ?string
    {
        $cached = $this->cache->find($localName);
        if ($cached !== null) {
            return $cached;
        }

        $translated = $this->googleTranslate->translate($localName, 'en')
            ?? $this->aiTranslate->translate($localName, 'en');
        if ($translated === null) {
            return null;
        }

        $this->cache->store($localName, $translated);
        return $translated;
    }
}
