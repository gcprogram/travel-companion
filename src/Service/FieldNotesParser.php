<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parses a Geocaching "field notes" export (GC-code,ISO-8601
 * timestamp,log type,"comment" per line - the format geocaching.com itself
 * generates and c:geo can also produce) into found/DNF signals keyed by GC
 * code. Exists because a c:geo cache-list GPX export often does NOT carry
 * enough of the cache's own <groundspeak:logs> for GeocachingGpxParser's
 * own-log matching to find the traveller's log (c:geo only stores a
 * handful of recent logs per cache, and an older find can fall out of that
 * window) - field notes are a direct, guaranteed record of the user's own
 * logs instead, at the cost of having no coordinates of their own (the
 * caller still needs a cache-list GPX to resolve GC code -> lat/lng).
 */
final class FieldNotesParser
{
    private const GC_CODE_PATTERN = '/^GC[0-9A-Z]{1,5}$/';
    private const FOUND_LOG_TYPES = ['found it', 'attended', 'webcam photo taken'];
    private const DNF_LOG_TYPES = ["didn't find it", 'did not find it'];

    /**
     * @return array<string, array{type: 'found'|'dnf', date: string}>
     */
    public function parse(string $text): array
    {
        $text = $this->toUtf8($text);

        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $fields = str_getcsv($line);
            if (count($fields) < 3) {
                continue;
            }

            $gcCode = strtoupper(trim($fields[0]));
            if (!preg_match(self::GC_CODE_PATTERN, $gcCode)) {
                continue;
            }

            $timestamp = trim($fields[1]);
            $date = strtotime($timestamp);
            if ($date === false) {
                continue;
            }

            $logType = strtolower(trim($fields[2]));
            $type = null;
            if (in_array($logType, self::FOUND_LOG_TYPES, true)) {
                $type = 'found';
            } elseif (in_array($logType, self::DNF_LOG_TYPES, true)) {
                $type = 'dnf';
            } else {
                continue; // "Write note" and everything else isn't a visit signal.
            }

            // Last line for a GC code wins, and a later find always beats an
            // earlier DNF on the same cache - same precedence rule as
            // GeocachingGpxParser's own-log/GSAK handling.
            if (isset($result[$gcCode]) && $result[$gcCode]['type'] === 'found' && $type === 'dnf') {
                continue;
            }
            $result[$gcCode] = ['type' => $type, 'date' => date('Y-m-d', $date)];
        }
        return $result;
    }

    /**
     * geocaching.com's own field-notes.txt export is UTF-16LE - usually
     * without a BOM in practice, discovered against Stefan's real download
     * (str_getcsv()/preg_split() on raw UTF-16 bytes silently produced
     * nothing usable: every ASCII byte is followed by a NUL byte, so line
     * boundaries and the GC-code regex never matched). Detects a BOM when
     * present and falls back to a byte-pattern heuristic (every other byte
     * NUL) for the common BOM-less case.
     */
    private function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xFF\xFE")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }
        if (strlen($raw) > 4 && $raw[1] === "\0" && $raw[3] === "\0") {
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        }
        return $raw;
    }
}
