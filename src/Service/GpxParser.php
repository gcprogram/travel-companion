<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parses <trkpt> points out of an uploaded GPX file. Uses DOMDocument +
 * XPath with local-name() matching instead of SimpleXML/namespace
 * registration, because real-world GPX exports vary in which namespace URI
 * (or none) they declare, and local-name() matching sidesteps that entirely.
 */
final class GpxParser
{
    /**
     * @return list<array{lat: float, lng: float, elevation: ?float, recordedAt: ?string, accuracy: ?float}>
     */
    public function parse(string $xml): array
    {
        $doc = new \DOMDocument();
        $ok = @$doc->loadXML($xml, LIBXML_NONET);
        if (!$ok) {
            return [];
        }

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//*[local-name()="trkpt"]');
        if ($nodes === false) {
            return [];
        }

        $points = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $lat = $node->getAttribute('lat');
            $lng = $node->getAttribute('lon');
            if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }
            if ((float) $lat < -90.0 || (float) $lat > 90.0 || (float) $lng < -180.0 || (float) $lng > 180.0) {
                continue;
            }

            $elevation = $this->firstChildValue($xpath, $node, 'ele');
            // hAcc (Garmin TrackPointExtension) and hdop are both best-effort
            // accuracy proxies; whichever a given export provides, we take it.
            $accuracy = $this->firstChildValue($xpath, $node, 'hAcc')
                ?? $this->firstChildValue($xpath, $node, 'hdop');

            $points[] = [
                'lat' => round((float) $lat, 6),
                'lng' => round((float) $lng, 6),
                'elevation' => $elevation !== null && is_numeric($elevation) ? (float) $elevation : null,
                'recordedAt' => $this->normalizeTime($this->firstChildValue($xpath, $node, 'time')),
                'accuracy' => $accuracy !== null && is_numeric($accuracy) ? (float) $accuracy : null,
            ];
        }

        return $points;
    }

    private function firstChildValue(\DOMXPath $xpath, \DOMElement $node, string $localName): ?string
    {
        $result = $xpath->query('.//*[local-name()="' . $localName . '"]', $node);
        if ($result === false || $result->length === 0) {
            return null;
        }
        $value = trim((string) $result->item(0)->nodeValue);
        return $value === '' ? null : $value;
    }

    private function normalizeTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($time);
        } catch (\Exception) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
