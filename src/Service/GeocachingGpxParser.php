<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parses a Geocaching GPX (c:geo / GSAK / geocaching.com pocket-query
 * export) into a flat list of found caches - structure and found-detection
 * logic ported from GCToolkit-android's GpxImporter.kt (a sibling project of
 * Stefan's, referenced as the source for this). Namespace-agnostic: element
 * names are matched by their local name only (prefix stripped by hand, not
 * via DOM namespace resolution), the same trick GpxImporter.kt uses,
 * because real-world exports are inconsistent about declaring the
 * groundspeak/gsak namespace prefixes they use.
 *
 * "Found" combines two independent signals (chosen deliberately over just
 * one, see HANDOVER.md step 6): a GSAK export's <gsak:UserFound> (treated as
 * found for any non-empty, non-"false" value - real GSAK exports often put a
 * date in there, not literally "true"), OR the caller's own GC username
 * matching a "Found it"/"Attended"/"Webcam photo taken" log in the file's
 * <groundspeak:logs> (present in any normal PQ/c:geo export, no GSAK
 * needed). Unlike GpxImporter.kt this deliberately does NOT parse
 * additional/stage waypoints, personal notes, or a "My Finds" PQ override -
 * out of scope for "import found caches as sights on a trip".
 */
final class GeocachingGpxParser
{
    private const GC_CODE_PATTERN = '/^GC[0-9A-Z]{1,5}$/';
    private const FOUND_LOG_TYPES = ['found it', 'attended', 'webcam photo taken'];

    /**
     * @return list<array{gcCode: string, name: string, lat: float, lng: float,
     *     cacheType: string, difficulty: ?float, terrain: ?float, found: bool, foundDate: ?string}>
     */
    public function parse(string $xml, string $username = ''): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        libxml_clear_errors();
        if (!$ok) {
            return [];
        }

        $caches = [];
        foreach ($dom->getElementsByTagName('wpt') as $wpt) {
            if (!$wpt instanceof \DOMElement) {
                continue;
            }
            $cache = $this->parseWaypoint($wpt, trim($username));
            if ($cache !== null) {
                // A cache can legitimately appear twice (main PQ + a -wpts
                // file, or a duplicate waypoint) - last one wins, matching
                // GpxImporter.kt's LinkedHashMap-by-gccode behaviour.
                $caches[$cache['gcCode']] = $cache;
            }
        }
        return array_values($caches);
    }

    /**
     * @return array{gcCode: string, name: string, lat: float, lng: float,
     *     cacheType: string, difficulty: ?float, terrain: ?float, found: bool, foundDate: ?string}|null
     */
    private function parseWaypoint(\DOMElement $wpt, string $username): ?array
    {
        $gcCode = $this->childText($wpt, 'name');
        if ($gcCode === null || !preg_match(self::GC_CODE_PATTERN, $gcCode)) {
            return null; // Not a cache waypoint (a stage/parking/etc.) - out of scope here.
        }

        $lat = $wpt->getAttribute('lat');
        $lng = $wpt->getAttribute('lon');
        if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $gs = $this->firstChild($wpt, 'cache');

        $rawType = $gs !== null ? $this->childText($gs, 'type') : null;
        $cacheType = CacheTypes::normalizeLong($rawType ?? '');

        $title = $gs !== null ? $this->childText($gs, 'name') : null;
        $difficulty = $gs !== null ? $this->floatOrNull($this->childText($gs, 'difficulty')) : null;
        $terrain = $gs !== null ? $this->floatOrNull($this->childText($gs, 'terrain')) : null;

        $gsakExt = $this->firstChild($wpt, 'wptExtension');
        $userFoundRaw = $gsakExt !== null ? $this->childText($gsakExt, 'UserFound') : null;
        $gsakFound = $userFoundRaw !== null
            && trim($userFoundRaw) !== ''
            && !in_array(strtolower(trim($userFoundRaw)), ['false', '0'], true);

        [$ownFound, $ownFoundDate] = $gs !== null
            ? $this->ownFoundLog($gs, $username)
            : [false, null];

        return [
            'gcCode' => $gcCode,
            'name' => ($title !== null && $title !== '') ? $title : $gcCode,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'cacheType' => $cacheType,
            'difficulty' => $difficulty,
            'terrain' => $terrain,
            'found' => $gsakFound || $ownFound,
            'foundDate' => $ownFoundDate,
        ];
    }

    /**
     * The date of the caller's own found log ("YYYY-MM-DD"), or null. A
     * standard PQ/c:geo export carries the cache's recent logs, so a
     * traveller's own "Found it" is normally in there - no GSAK needed,
     * just their GC username to match against <groundspeak:finder>.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function ownFoundLog(\DOMElement $gs, string $username): array
    {
        if ($username === '') {
            return [false, null];
        }
        $logs = $this->firstChild($gs, 'logs');
        if ($logs === null) {
            return [false, null];
        }
        foreach ($this->children($logs, 'log') as $log) {
            $type = strtolower(trim((string) $this->childText($log, 'type')));
            if (!in_array($type, self::FOUND_LOG_TYPES, true)) {
                continue;
            }
            $finder = trim((string) $this->childText($log, 'finder'));
            if (strcasecmp($finder, $username) === 0) {
                $date = $this->childText($log, 'date');
                return [true, $date !== null ? substr($date, 0, 10) : null];
            }
        }
        return [false, null];
    }

    private function floatOrNull(?string $value): ?float
    {
        if ($value === null || trim($value) === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * Local element name with any namespace prefix stripped by hand (e.g.
     * "groundspeak:cache" -> "cache") - deliberately not DOM's own
     * namespace-aware localName, since that returns null for an element
     * whose prefix was used without a matching xmlns declaration, which
     * real-world c:geo/GSAK exports aren't always careful about.
     */
    private function localName(\DOMNode $node): string
    {
        $name = $node->nodeName;
        $pos = strrpos($name, ':');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /**
     * @return list<DOMElement>
     */
    private function children(\DOMElement $parent, string $localName): array
    {
        $result = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $this->localName($child) === $localName) {
                $result[] = $child;
            }
        }
        return $result;
    }

    private function firstChild(\DOMElement $parent, string $localName): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $this->localName($child) === $localName) {
                return $child;
            }
        }
        return null;
    }

    private function childText(\DOMElement $parent, string $localName): ?string
    {
        $child = $this->firstChild($parent, $localName);
        return $child !== null ? trim($child->textContent) : null;
    }
}
