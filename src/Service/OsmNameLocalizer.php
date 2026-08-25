<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shared name-picking pipeline for anything backed by OSM data - a
 * sightseeing hit (PoiDiscoveryService) or a reverse-geocoded stay landmark
 * (ReverseGeocodingService): name:de wins over name:en (Stefan's own
 * phrasing: "deutsch/englisch" in that order), both win over the bare local
 * name whenever it's in a script most of this app's users can't read. A
 * local name that IS already Latin-script (French, Vietnamese with
 * diacritics, ...) is used as-is - only non-Latin script triggers a
 * translation attempt, and only when name:de/name:en aren't already there
 * to answer the same need for free.
 *
 * Originally built only for sights (discovered against a Thailand/Russia-
 * adjacent trip), then extended to stays on Stefan's explicit ask ("Gleiche
 * Pipeline bitte") once he noticed a stay's own resolved address could be
 * just as unreadable - a shop sign in Cyrillic, say - with no translation
 * attempt at all up to that point.
 */
final class OsmNameLocalizer
{
    public function __construct(private readonly PoiNameTranslationService $translator)
    {
    }

    /**
     * @param array<string, string> $tags
     */
    public function fromTags(array $tags): ?string
    {
        $nameDe = $tags['name:de'] ?? null;
        if (is_string($nameDe) && $nameDe !== '') {
            return mb_substr($nameDe, 0, 190);
        }
        $nameEn = $tags['name:en'] ?? null;
        if (is_string($nameEn) && $nameEn !== '') {
            return mb_substr($nameEn, 0, 190);
        }

        $local = $tags['name'] ?? null;
        if (!is_string($local) || $local === '') {
            return null;
        }
        return $this->localize($local);
    }

    /**
     * For a name not backed by OSM name:de/name:en tags at all (e.g. a
     * Nominatim-composed address) - still worth a translation attempt if
     * it's non-Latin script, same as the tag-driven path.
     *
     * Best-effort: falls through to the (unreadable, but still real) local
     * name rather than ever discarding the place - a name nobody asked to
     * see is still better than the place vanishing outright.
     */
    public function localize(string $local): string
    {
        if (!$this->needsTranslation($local)) {
            return mb_substr($local, 0, 190);
        }

        $translated = $this->translator->translate($local);
        return mb_substr($translated ?? $local, 0, 190);
    }

    /**
     * Whether $name contains any character outside Latin script (plus
     * digits/punctuation/whitespace) - Cyrillic, Thai, CJK, Arabic, and
     * every other non-Latin script all trigger this the same way.
     */
    public function needsTranslation(string $name): bool
    {
        $stripped = preg_replace('/[\p{Latin}\p{N}\p{P}\p{Z}\p{Cf}]/u', '', $name);
        return $stripped !== null && $stripped !== '';
    }
}
