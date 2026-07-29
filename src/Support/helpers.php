<?php

declare(strict_types=1);

/**
 * Globale Template-Helfer. Wird über composer.json (autoload.files) geladen.
 */

if (!function_exists('e')) {
    /**
     * HTML-Escaping-Kurzform für Templates: <?= e($title) ?>
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('format_date')) {
    /**
     * 'YYYY-MM-DD' (DB) -> 'DD.MM.YYYY' (Anzeige). Ungültige Werte unverändert zurückgeben.
     */
    function format_date(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '') {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $isoDate);
        return $dt !== false ? $dt->format('d.m.Y') : $isoDate;
    }
}

if (!function_exists('mood_emoji')) {
    /**
     * Stimmungswert (day_entries.mood) -> Emoji für die Anzeige.
     */
    function mood_emoji(?string $mood): string
    {
        return match ($mood) {
            'sehr_schlecht' => '😞',
            'schlecht' => '🙁',
            'neutral' => '😐',
            'gut' => '🙂',
            'sehr_gut' => '😄',
            default => '',
        };
    }
}

if (!function_exists('mood_label')) {
    /**
     * Stimmungswert (day_entries.mood) -> deutsches Label (z.B. für title-Attribute).
     */
    function mood_label(?string $mood): string
    {
        return match ($mood) {
            'sehr_schlecht' => 'Sehr schlecht',
            'schlecht' => 'Schlecht',
            'neutral' => 'Neutral',
            'gut' => 'Gut',
            'sehr_gut' => 'Sehr gut',
            default => '',
        };
    }
}

if (!function_exists('weather_description')) {
    /**
     * WMO-Wettercode (Open-Meteo) -> kurze deutsche Beschreibung.
     * Grobe Gruppierung, nicht jeder der ~30 Codes braucht einen eigenen Text.
     */
    function weather_description(int $code): string
    {
        return match (true) {
            $code === 0 => 'Klar',
            $code <= 3 => 'Bewölkt',
            $code === 45 || $code === 48 => 'Nebel',
            $code >= 51 && $code <= 57 => 'Nieselregen',
            $code >= 61 && $code <= 67 => 'Regen',
            $code >= 71 && $code <= 77 => 'Schnee',
            $code >= 80 && $code <= 82 => 'Regenschauer',
            $code >= 85 && $code <= 86 => 'Schneeschauer',
            $code >= 95 => 'Gewitter',
            default => 'Wechselhaft',
        };
    }
}

if (!function_exists('format_date_range')) {
    /**
     * Zeitraum kompakt darstellen: "01.06. – 14.06.2026" bzw. über Jahresgrenzen voll.
     */
    function format_date_range(?string $start, ?string $end): string
    {
        if (($start === null || $start === '') && ($end === null || $end === '')) {
            return '';
        }
        $s = $start ? \DateTimeImmutable::createFromFormat('Y-m-d', $start) : false;
        $e = $end ? \DateTimeImmutable::createFromFormat('Y-m-d', $end) : false;

        if ($s && $e) {
            if ($s->format('Y') === $e->format('Y')) {
                return $s->format('d.m.') . ' – ' . $e->format('d.m.Y');
            }
            return $s->format('d.m.Y') . ' – ' . $e->format('d.m.Y');
        }
        if ($s) {
            return 'ab ' . $s->format('d.m.Y');
        }
        return $e ? 'bis ' . $e->format('d.m.Y') : '';
    }
}
