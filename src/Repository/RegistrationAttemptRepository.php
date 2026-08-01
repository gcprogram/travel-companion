<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Anti-abuse log for self-registration. 'failed' is never written directly —
 * it's computed lazily: a 'started' row counts as failed once its
 * confirmation window has passed with no later 'confirmed' row for the same
 * email. That avoids needing a separate sweep/cron to flip state.
 */
final class RegistrationAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $ip, ?string $email, string $result): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_attempts (ip, email, result, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ip, $email, $result, gmdate('Y-m-d H:i:s')]);
        $this->prune();
    }

    /**
     * Count of 'started' attempts from this IP whose confirmation window
     * ($ttlSeconds) has elapsed with no later 'confirmed' row for the same
     * email, within the last $windowSeconds.
     */
    public function countFailedForIp(string $ip, int $ttlSeconds, int $windowSeconds): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $ttlSeconds);
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM registration_attempts ra1
             WHERE ra1.ip = ? AND ra1.result = 'started' AND ra1.created_at < ? AND ra1.created_at >= ?
             AND NOT EXISTS (
                 SELECT 1 FROM registration_attempts ra2
                 WHERE ra2.email = ra1.email AND ra2.result = 'confirmed' AND ra2.created_at >= ra1.created_at
             )"
        );
        $stmt->execute([$ip, $cutoff, $since]);
        return (int) $stmt->fetchColumn();
    }

    public function countDistinctEmailsForIp(string $ip, int $windowSeconds): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT email) FROM registration_attempts
             WHERE ip = ? AND result = 'started' AND created_at >= ? AND email IS NOT NULL"
        );
        $stmt->execute([$ip, $since]);
        return (int) $stmt->fetchColumn();
    }

    public function hasRecentStartForEmail(string $email, int $windowSeconds): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM registration_attempts WHERE email = ? AND result = 'started' AND created_at >= ? LIMIT 1"
        );
        $stmt->execute([$email, $since]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Keeps the table small; nothing needs rows older than the longest
     * lookback window any check uses (25h) plus slack for clock drift/DST.
     */
    private function prune(): void
    {
        $this->pdo->exec('DELETE FROM registration_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL 48 HOUR');
    }
}
