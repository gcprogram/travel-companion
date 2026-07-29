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
     * "Norwegen 2026 – Fjorde & Städte" -> "norwegen-2026-fjorde-staedte"
     * Bei Kollision wird -2, -3, ... angehängt.
     */
    public function uniqueTripSlug(string $title, ?int $exceptTripId = null): string
    {
        $base = $this->slugify($title);
        if ($base === '') {
            $base = 'reise';
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

        // Weitere Diakritika nach ASCII überführen, wenn intl verfügbar ist (auf dem Hosting: ja).
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
