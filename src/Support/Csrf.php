<?php

declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Hidden form field to include in every POST form.
     */
    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->token() . '">';
    }

    public function isValid(mixed $submitted): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        return is_string($expected) && is_string($submitted) && hash_equals($expected, $submitted);
    }
}
