<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal static translation lookup, mirroring Env's no-dependency style.
 * Catalogs are plain PHP arrays under /lang; missing keys fall back to the
 * default locale, then to the key itself so a missing translation never
 * breaks rendering.
 */
final class Translator
{
    private const SUPPORTED = ['en', 'de'];
    private const DEFAULT_LOCALE = 'en';

    private static string $locale = self::DEFAULT_LOCALE;

    /** @var array<string, array<string, string>> */
    private static array $catalog = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT_LOCALE;
        self::load(self::$locale);
        self::load(self::DEFAULT_LOCALE);
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * @return list<string>
     */
    public static function supportedLocales(): array
    {
        return self::SUPPORTED;
    }

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    /**
     * @param array<string, string|int> $params
     */
    public static function translate(string $key, array $params = []): string
    {
        $text = self::$catalog[self::$locale][$key] ?? self::$catalog[self::DEFAULT_LOCALE][$key] ?? $key;

        if ($params === []) {
            return $text;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements[':' . $name] = (string) $value;
        }
        return strtr($text, $replacements);
    }

    private static function load(string $locale): void
    {
        if (isset(self::$catalog[$locale])) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
        self::$catalog[$locale] = is_file($file) ? require $file : [];
    }
}
