<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One-time messages that survive a redirect (success/error/info).
 */
final class Flash
{
    private const SESSION_KEY = '_flash';

    public function add(string $type, string $message): void
    {
        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'message' => $message];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public function pull(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);
        return $messages;
    }
}
