<?php

declare(strict_types=1);

/**
 * Global template helpers, loaded via composer.json (autoload.files).
 */

if (!function_exists('e')) {
    /**
     * HTML-escaping shorthand for templates: <?= e($title) ?>
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
    /**
     * Translation shorthand for templates and controllers: <?= t('nav.login') ?>
     *
     * @param array<string, string|int> $params
     */
    function t(string $key, array $params = []): string
    {
        return \App\Support\Translator::translate($key, $params);
    }
}

if (!function_exists('current_locale')) {
    function current_locale(): string
    {
        return \App\Support\Translator::locale();
    }
}

if (!function_exists('format_date')) {
    /**
     * 'YYYY-MM-DD' (DB) -> 'DD.MM.YYYY' (display). Invalid values pass through unchanged.
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
     * day_entries.mood -> emoji for display. Emoji are language-neutral,
     * so this doesn't go through the translator.
     */
    function mood_emoji(?string $mood): string
    {
        return match ($mood) {
            'very_bad' => '😞',
            'bad' => '🙁',
            'neutral' => '😐',
            'good' => '🙂',
            'very_good' => '😄',
            default => '',
        };
    }
}

if (!function_exists('mood_label')) {
    /**
     * day_entries.mood -> translated label (e.g. for title attributes).
     */
    function mood_label(?string $mood): string
    {
        if ($mood === null) {
            return '';
        }
        return t('mood.' . $mood);
    }
}

if (!function_exists('weather_description')) {
    /**
     * WMO weather code (Open-Meteo) -> short translated description.
     * Coarse grouping; not every one of the ~30 codes needs its own text.
     */
    function weather_description(int $code): string
    {
        $key = match (true) {
            $code === 0 => 'weather.clear',
            $code <= 3 => 'weather.cloudy',
            $code === 45 || $code === 48 => 'weather.fog',
            $code >= 51 && $code <= 57 => 'weather.drizzle',
            $code >= 61 && $code <= 67 => 'weather.rain',
            $code >= 71 && $code <= 77 => 'weather.snow',
            $code >= 80 && $code <= 82 => 'weather.rain_showers',
            $code >= 85 && $code <= 86 => 'weather.snow_showers',
            $code >= 95 => 'weather.thunderstorm',
            default => 'weather.variable',
        };
        return t($key);
    }
}

if (!function_exists('format_date_range')) {
    /**
     * Compact date range display: "01.06. – 14.06.2026", full dates across year boundaries.
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
            return t('date_range.from') . ' ' . $s->format('d.m.Y');
        }
        return $e ? t('date_range.until') . ' ' . $e->format('d.m.Y') : '';
    }
}
