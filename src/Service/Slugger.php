<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TripRepository;

final class Slugger
{
    public function __construct(private readonly TripRepository $trips)
    {
    }

    /**
     * "Norway 2026 – Fjords & Cities" -> "norway-2026-fjords-cities"
     * On collision, -2, -3, ... is appended.
     */
    public function uniqueTripSlug(string $title, ?int $exceptTripId = null): string
    {
        $base = $this->slugify($title);
        if ($base === '') {
            $base = 'trip';
        }

        $slug = $base;
        $i = 2;
        while ($this->trips->slugExists($slug, $exceptTripId)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        // Transliterate remaining diacritics to ASCII when intl is available (it is on our hosting).
        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }

        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }
}
