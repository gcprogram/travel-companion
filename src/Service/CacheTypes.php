<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Geocaching cache-type lookup, ported from GCToolkit-android's
 * CacheTypes.kt/CacheIcons.kt (a sibling project of Stefan's, referenced as
 * the source for icons/GPX structure for GeocachingGpxParser) - canonical
 * short codes ("Tradi", "Mystery", ...), the long GPX type string each one
 * normalizes from, and the SVG icon file (copied 1:1 from that project into
 * public/assets/img/cache_icons/) + badge colour for each.
 */
final class CacheTypes
{
    /** Long GPX <groundspeak:type> string (lowercased) -> canonical short code. */
    private const LONG_TO_SHORT = [
        'cache in trash out event' => 'CITO',
        'community celebration event' => 'CCE',
        'earthcache' => 'Earth',
        'event cache' => 'Event',
        'gps adventures exhibit' => 'Maze',
        'gps adventures maze exhibit' => 'Maze',
        'geocaching hq block party' => 'Block Party',
        'geocaching hq celebration' => 'HQEvent',
        'giga-event cache' => 'Giga',
        'groundspeak hq' => 'HQ',
        'letterbox hybrid' => 'Letter',
        'locationless (reverse) cache' => 'Reverse',
        'mega-event cache' => 'Mega',
        'multi-cache' => 'Multi',
        'project ape cache' => 'APE',
        'traditional cache' => 'Tradi',
        'unknown cache' => 'Mystery',
        'unknown (mystery) cache' => 'Mystery',
        'virtual cache' => 'Virtual',
        'webcam cache' => 'Webcam',
        'wherigo cache' => 'Wherigo',
        'lab cache' => 'Lab',
        'adventure lab' => 'Lab',
    ];

    /** Canonical short code -> SVG asset filename under public/assets/img/cache_icons/. */
    private const SVG_BY_TYPE = [
        'Tradi' => 'type_traditional',
        'Multi' => 'type_multi',
        'Virtual' => 'type_virtual',
        'Letter' => 'type_letterbox',
        'Event' => 'type_event',
        'Mystery' => 'type_mystery',
        'APE' => 'type_ape',
        'Webcam' => 'type_webcam',
        'Reverse' => 'type_unknown',
        'CITO' => 'type_cito',
        'Earth' => 'type_earth',
        'Mega' => 'type_mega',
        'Maze' => 'type_maze',
        'Wherigo' => 'type_wherigo',
        'CCE' => 'type_specialevent',
        'HQ' => 'type_hq',
        'HQEvent' => 'type_specialevent',
        'Block Party' => 'type_specialevent',
        'Giga' => 'type_giga',
        'Lab' => 'type_advlab',
    ];

    /** Canonical short code -> badge colour (same palette as GCToolkit-android). */
    private const COLOR_BY_TYPE = [
        'Tradi' => '#388E3C',
        'Multi' => '#F57C00',
        'Virtual' => '#0288D1',
        'Letter' => '#303F9F',
        'Event' => '#D32F2F',
        'Mystery' => '#303F9F',
        'APE' => '#AFB42B',
        'Webcam' => '#0288D1',
        'Reverse' => '#616161',
        'CITO' => '#D32F2F',
        'Earth' => '#0288D1',
        'Mega' => '#D32F2F',
        'Maze' => '#AFB42B',
        'Wherigo' => '#303F9F',
        'CCE' => '#D32F2F',
        'HQ' => '#AFB42B',
        'HQEvent' => '#D32F2F',
        'Block Party' => '#D32F2F',
        'Giga' => '#D32F2F',
        'Lab' => '#7B1FA2',
    ];

    private const UNKNOWN_SVG = 'type_unknown';
    private const UNKNOWN_COLOR = '#616161';

    /**
     * GPX long type string -> canonical short code. Passes an already-short
     * or unrecognized value through unchanged (matches GCToolkit-android's
     * normaliseTypeLong()) so an odd/future Groundspeak type doesn't just vanish.
     */
    public static function normalizeLong(string $raw): string
    {
        $key = strtolower(trim($raw));
        return self::LONG_TO_SHORT[$key] ?? trim($raw);
    }

    public static function iconUrl(?string $shortCode): string
    {
        $svg = self::SVG_BY_TYPE[$shortCode ?? ''] ?? self::UNKNOWN_SVG;
        return '/assets/img/cache_icons/' . $svg . '.svg';
    }

    public static function badgeColor(?string $shortCode): string
    {
        return self::COLOR_BY_TYPE[$shortCode ?? ''] ?? self::UNKNOWN_COLOR;
    }
}
