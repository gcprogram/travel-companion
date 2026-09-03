<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Personal MCP API tokens (migration 0040). Only the SHA-256 hash is ever
 * stored - same pattern as EmailConfirmationRepository/PasswordResetRepository
 * - the raw token exists only transiently in the creating request/response,
 * never written to the DB and never recoverable afterwards.
 */
final class McpApiTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $label, string $tokenHash): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO mcp_api_tokens (user_id, label, token_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $label, $tokenHash, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mcp_api_tokens WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Resolves a bearer token to its owning, still-active (unrevoked) row -
     * the caller (McpAuthMiddleware) still needs to check the owning
     * user is active, same as the session-based AuthService::currentUser().
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mcp_api_tokens WHERE token_hash = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function touchLastUsed(int $id): void
    {
        $this->pdo->prepare('UPDATE mcp_api_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * Scoped to $userId so a user can only ever revoke their own token, even
     * if they somehow guessed another token's numeric id.
     */
    public function revoke(int $id, int $userId): void
    {
        $this->pdo->prepare('UPDATE mcp_api_tokens SET revoked_at = ? WHERE id = ? AND user_id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $id, $userId]);
    }
}
