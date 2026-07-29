<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env loader with no external dependency.
 * Supports KEY=value, comments (#), and "quoted values".
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            return; // .env is optional; values can also be real environment variables.
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === "" || str_starts_with($line, "#") || !str_contains($line, "=")) {
                continue;
            }
            [$key, $value] = explode("=", $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== "" && ($value[0] === "\"" || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                $value = $end !== false && $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
            } elseif (($pos = strpos($value, " #")) !== false) {
                $value = rtrim(substr($value, 0, $pos));
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false) {
            return $fromEnv;
        }
        return self::$values[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === "") {
            throw new \RuntimeException(sprintf("Environment variable \"%s\" is missing (see .env.example).", $key));
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ["1", "true", "yes", "on"], true);
    }
}
