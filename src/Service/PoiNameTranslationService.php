<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PoiNameTranslationRepository;

/**
 * Translates a non-Latin-script sight name (Thai, Cyrillic, CJK, Arabic, ...)
 * into English via GoogleTranslateService - see PoiDiscoveryService for why
 * this exists: an OSM element without a name:de/name:en tag would otherwise
 * show up in the sights list in a script most travellers can't read at all,
 * which used to mean silently keeping the unreadable local name (never
 * discarding the sight itself - that's still true here, just no longer the
 * ONLY option).
 *
 * Deliberately a dedicated translation API rather than the chat-completion
 * AI provider (ai.* settings) - Stefan's own call: sight-name translation
 * can mean a lot of small requests, and he'd rather that draw from its own
 * separate Google Translate quota than compete with the "real" AI features'
 * budget. See GoogleTranslateService for the rest of that reasoning.
 *
 * Best-effort: returns null on any failure (no key configured, network, bad
 * response) rather than throwing - the caller already knows to fall back to
 * the raw local name, same "cosmetic gap, never worth failing over"
 * reasoning as everywhere else this pattern is used.
 */
final class PoiNameTranslationService
{
    public function __construct(
        private readonly GoogleTranslateService $googleTranslate,
        private readonly PoiNameTranslationRepository $cache,
    ) {
    }

    public function translate(string $localName): ?string
    {
        $cached = $this->cache->find($localName);
        if ($cached !== null) {
            return $cached;
        }

        $translated = $this->googleTranslate->translate($localName, 'en');
        if ($translated === null) {
            return null;
        }

        $this->cache->store($localName, $translated);
        return $translated;
    }
}
