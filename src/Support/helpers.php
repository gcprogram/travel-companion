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

if (!function_exists('weather_emoji')) {
    /**
     * WMO weather code (Open-Meteo) -> emoji, same code groupings as
     * weather_description() so the two always agree on what a code means.
     * Emoji are language-neutral, so this doesn't go through the translator.
     */
    function weather_emoji(?int $code): string
    {
        if ($code === null) {
            return '';
        }
        return match (true) {
            $code === 0 => '☀️',
            $code <= 3 => '⛅',
            $code === 45 || $code === 48 => '🌫️',
            $code >= 51 && $code <= 57 => '🌦️',
            $code >= 61 && $code <= 67 => '🌧️',
            $code >= 71 && $code <= 77 => '🌨️',
            $code >= 80 && $code <= 82 => '🌧️',
            $code >= 85 && $code <= 86 => '🌨️',
            $code >= 95 => '⛈️',
            default => '🌡️',
        };
    }
}

if (!function_exists('day_night_weather_summary')) {
    /**
     * Compact "high/low" reading from a day's 24 hourly rows (see
     * day_entry_weather_hours / DayEntryWeatherHourRepository) for the
     * collapsed diary entry header - the warmest daytime hour (06-17) and
     * the coldest evening/night hour (18-23, 00-05), rather than every hour
     * (that's what the full hourly table, still one click away, is for).
     *
     * @param list<array<string, mixed>> $hours
     * @return array{day: ?array{tempC: float, code: ?int}, night: ?array{tempC: float, code: ?int}}
     */
    function day_night_weather_summary(array $hours): array
    {
        $day = null;
        $night = null;
        foreach ($hours as $h) {
            if ($h['temp_c'] === null) {
                continue;
            }
            $hour = (int) $h['hour'];
            $temp = (float) $h['temp_c'];
            $code = $h['weather_code'] !== null ? (int) $h['weather_code'] : null;
            if ($hour >= 6 && $hour <= 17) {
                if ($day === null || $temp > $day['tempC']) {
                    $day = ['tempC' => $temp, 'code' => $code];
                }
            } elseif ($night === null || $temp < $night['tempC']) {
                $night = ['tempC' => $temp, 'code' => $code];
            }
        }
        return ['day' => $day, 'night' => $night];
    }
}

if (!function_exists('format_datetime')) {
    /**
     * 'YYYY-MM-DD HH:MM:SS' (DB, UTC) -> 'DD.MM.YYYY HH:MM' (display).
     * Unlike format_date(), keeps the time component - used for admin
     * timestamps (registered/last login) where the date alone isn't enough
     * to distinguish same-day events. Invalid values pass through unchanged.
     */
    function format_datetime(?string $isoDateTime): string
    {
        if ($isoDateTime === null || $isoDateTime === '') {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $isoDateTime);
        return $dt !== false ? $dt->format('d.m.Y H:i') : $isoDateTime;
    }
}

if (!function_exists('format_short_datetime')) {
    /**
     * 'YYYY-MM-DD HH:MM:SS' (DB, UTC) -> 'DD MMM HH:MM' (display, e.g. "25 Jul
     * 14:32") - compact enough for the POI list's "closest approach" column,
     * where format_datetime()'s full 'DD.MM.YYYY' would push the distance
     * off narrow screens. Invalid values pass through unchanged.
     */
    function format_short_datetime(?string $isoDateTime): string
    {
        if ($isoDateTime === null || $isoDateTime === '') {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $isoDateTime);
        return $dt !== false ? $dt->format('d M H:i') : $isoDateTime;
    }
}

if (!function_exists('format_bytes')) {
    /**
     * Byte count -> compact human-readable size (MB below 1 GB, GB above).
     */
    function format_bytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 1, ',', '.') . ' GB';
        }
        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
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
