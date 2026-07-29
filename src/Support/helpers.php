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
