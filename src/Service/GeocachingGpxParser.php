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
 * "Found" combines up to three independent signals (chosen deliberately
 * over just one, see HANDOVER.md step 6): a GSAK export's <gsak:UserFound>
 * (treated as found for any non-empty, non-"false" value - real GSAK
 * exports often put a date in there, not literally "true"), the caller's
 * own GC username matching a "Found it"/"Attended"/"Webcam photo taken" log
 * in the file's <groundspeak:logs> (present in any normal PQ/c:geo export,
 * no GSAK needed), OR an optional externally-supplied field-notes map (see
 * FieldNotesParser) keyed by GC code - a fallback for c:geo exports that
 * simply don't carry enough of a cache's log history for the own-log match
 * to find the traveller's own log. A DNF (Did Not Find) is detected the
 * same way across all three sources - and reported separately (never both
 * at once: an actual find always wins over a stray older DNF signal on the
 * same cache). Unlike GpxImporter.kt this deliberately does NOT parse
 * additional/stage waypoints, personal notes, or a "My Finds" PQ override -
 * out of scope for "import found/DNF caches as sights on a trip".
 */
final class GeocachingGpxParser
{
    private const GC_CODE_PATTERN = '/^GC[0-9A-Z]{1,5}$/';
    private const FOUND_LOG_TYPES = ['found it', 'attended', 'webcam photo taken'];
    private const DNF_LOG_TYPES = ["didn't find it", 'did not find it'];

    /**
     * @param array<string, array{type: 'found'|'dnf', date: string}> $fieldNotes
     * @return list<array{gcCode: string, name: string, lat: float, lng: float,
     *     cacheType: string, difficulty: ?float, terrain: ?float,
     *     found: bool, foundDate: ?string, dnf: bool, dnfDate: ?string}>
     */
    public function parse(string $xml, string $username = '', array $fieldNotes = []): array
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
            $cache = $this->parseWaypoint($wpt, trim($username), $fieldNotes);
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
     * @param array<string, array{type: 'found'|'dnf', date: string}> $fieldNotes
     * @return array{gcCode: string, name: string, lat: float, lng: float,
     *     cacheType: string, difficulty: ?float, terrain: ?float,
     *     found: bool, foundDate: ?string, dnf: bool, dnfDate: ?string}|null
     */
    private function parseWaypoint(\DOMElement $wpt, string $username, array $fieldNotes): ?array
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
        $gsakFound = $this->gsakFlagSet($gsakExt, 'UserFound');
        $gsakDnf = $this->gsakFlagSet($gsakExt, 'DNF');

        [$ownFound, $ownFoundDate] = $gs !== null
            ? $this->ownLogOfType($gs, $username, self::FOUND_LOG_TYPES)
            : [false, null];
        $note = $fieldNotes[$gcCode] ?? null;
        $noteFound = $note !== null && $note['type'] === 'found';
        $found = $gsakFound || $ownFound || $noteFound;
        if ($found && $ownFoundDate === null && $noteFound) {
            $ownFoundDate = $note['date'];
        }

        // A DNF only matters when there's no actual find - an older DNF log
        // sitting alongside a later "Found it" (second attempt) shouldn't
        // demote a real find.
        $dnf = false;
        $dnfDate = null;
        if (!$found) {
            $gsakDnfFlag = $gsakDnf;
            [$ownDnf, $ownDnfDateResult] = $gs !== null
                ? $this->ownLogOfType($gs, $username, self::DNF_LOG_TYPES)
                : [false, null];
            $noteDnf = $note !== null && $note['type'] === 'dnf';
            $dnf = $gsakDnfFlag || $ownDnf || $noteDnf;
            $dnfDate = $ownDnfDateResult ?? ($noteDnf ? $note['date'] : null);
        }

        return [
            'gcCode' => $gcCode,
            'name' => ($title !== null && $title !== '') ? $title : $gcCode,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'cacheType' => $cacheType,
            'difficulty' => $difficulty,
            'terrain' => $terrain,
            'found' => $found,
            'foundDate' => $ownFoundDate,
            'dnf' => $dnf,
            'dnfDate' => $dnfDate,
        ];
    }

    /**
     * A GSAK boolean-ish extension flag ("UserFound"/"DNF"): true for any
     * non-empty, non-"false" value - real GSAK exports often put a date in
     * UserFound rather than literally "true".
     */
    private function gsakFlagSet(?\DOMElement $gsakExt, string $tag): bool
    {
        if ($gsakExt === null) {
            return false;
        }
        $raw = $this->childText($gsakExt, $tag);
        return $raw !== null && trim($raw) !== '' && !in_array(strtolower(trim($raw)), ['false', '0'], true);
    }

    /**
     * The date of the caller's own matching log ("YYYY-MM-DD"), or null. A
     * standard PQ/c:geo export carries the cache's recent logs, so a
     * traveller's own "Found it"/"Didn't find it" is normally in there - no
     * GSAK needed, just their GC username to match against
     * <groundspeak:finder>.
     *
     * @param list<string> $logTypes
     * @return array{0: bool, 1: ?string}
     */
    private function ownLogOfType(\DOMElement $gs, string $username, array $logTypes): array
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
            if (!in_array($type, $logTypes, true)) {
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
