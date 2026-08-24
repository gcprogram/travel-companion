<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads Address/Persons/Caption/Transcript that AI MediaAnalyzer (Stefan's
 * separate desktop tool, github.com-hosted, not part of this repo) already
 * wrote into a photo/video's own file metadata before upload, under its own
 * private XMP namespace ('urn:ai-media-analyzer:metadata:1.0', prefix
 * 'aimedia' - see that project's media_tools.py, _EXIFTOOL_CONFIG_TEXT).
 * Deliberately does NOT read its 'Landmark' or 'Geocache' fields - this
 * app's own POI/geocache data is richer and takes precedence (Stefan's
 * call).
 *
 * A pure byte-scanning + DOM parser, no ExifTool/exec involved - Bitpalast
 * disables exec/shell_exec/proc_open, so shelling out was never an option.
 * AI MediaAnalyzer writes the same XMP packet into both JPEGs (APP1
 * segment) and MP4/MOV containers (both confirmed against media_tools.py:
 * image AND video go through the same _write_xmp_ai_metadata() via
 * ExifTool's own XMP embedding, unlike its .m4a-audio path which uses MP4
 * freeform atoms instead) - the packet itself is always a self-contained,
 * marker-delimited text block regardless of which container wraps it
 * ("<x:xmpmeta ...>...</x:xmpmeta>"), so one scanner handles both without
 * needing to understand JPEG segment or MP4 box framing at all.
 *
 * Reads in bounded chunks rather than loading the whole file into memory -
 * relevant for videos, which can be well into the hundreds of MB on
 * Bitpalast's 512M memory_limit (CLAUDE.md).
 *
 * Verified against a real file written by AI MediaAnalyzer's own
 * write_ai_metadata() (not a hand-built fixture) - including a value with
 * ß/ö/– to confirm those survive both the write and this read intact.
 * (AI MediaAnalyzer's own exiftool CLI *display* mangles them - confirmed
 * separately that the file bytes themselves are correct UTF-8; that's a
 * terminal/console encoding artifact of exiftool's own CLI output, not
 * data corruption - see the file the wrote for that in real testing.)
 */
final class AiMediaXmpReader
{
    private const START_MARKER = '<x:xmpmeta';
    private const END_MARKER = '</x:xmpmeta>';
    private const CHUNK_SIZE = 262144; // 256 KB
    // An AI MediaAnalyzer packet is a few KB even with exiftool's padding;
    // several MB is already an absurd upper bound, just a safety net
    // against scanning a false-positive start marker forever.
    private const MAX_PACKET_SIZE = 4 * 1024 * 1024;

    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const AIMEDIA_NS = 'urn:ai-media-analyzer:metadata:1.0';

    /**
     * @return array{address: ?string, persons: ?string, caption: ?string, transcript: ?string}
     */
    public function read(string $path): array
    {
        $empty = ['address' => null, 'persons' => null, 'caption' => null, 'transcript' => null];

        $packet = $this->extractXmpPacket($path);
        if ($packet === null) {
            return $empty;
        }

        return $this->parseAimediaFields($packet) ?? $empty;
    }

    private function extractXmpPacket(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $tail = '';
            $overlapLen = strlen(self::START_MARKER) - 1;
            $collected = null;

            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                if ($collected === null) {
                    $window = $tail . $chunk;
                    $pos = strpos($window, self::START_MARKER);
                    if ($pos === false) {
                        $tail = substr($window, -$overlapLen);
                        continue;
                    }
                    $collected = substr($window, $pos);
                } else {
                    $collected .= $chunk;
                }

                $endPos = strpos($collected, self::END_MARKER);
                if ($endPos !== false) {
                    return substr($collected, 0, $endPos + strlen(self::END_MARKER));
                }
                if (strlen($collected) > self::MAX_PACKET_SIZE) {
                    return null;
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{address: ?string, persons: ?string, caption: ?string, transcript: ?string}|null
     */
    private function parseAimediaFields(string $xml): ?array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if (!$ok) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rdf', self::RDF_NS);
        $xpath->registerNamespace('aimedia', self::AIMEDIA_NS);

        return [
            'address' => $this->queryField($xpath, 'Address'),
            'persons' => $this->queryField($xpath, 'Persons'),
            'caption' => $this->queryField($xpath, 'Caption'),
            'transcript' => $this->queryField($xpath, 'Transcript'),
        ];
    }

    /**
     * Element form ("<aimedia:Address>...</aimedia:Address>") is what
     * ExifTool actually writes (confirmed against a real written file) -
     * the attribute form is valid XMP too and checked defensively in case
     * that ever changes upstream.
     */
    private function queryField(\DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query("//rdf:Description/aimedia:{$name}");
        if ($nodes !== false && $nodes->length > 0) {
            $value = trim((string) $nodes->item(0)->textContent);
            return $value !== '' ? $value : null;
        }

        $attrs = $xpath->query("//rdf:Description/@aimedia:{$name}");
        if ($attrs !== false && $attrs->length > 0) {
            $value = trim((string) $attrs->item(0)->nodeValue);
            return $value !== '' ? $value : null;
        }

        return null;
    }
}
