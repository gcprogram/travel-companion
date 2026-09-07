<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Persistent, cross-request rate-limit state per AI purpose slot (see
 * migration 0042) - currently only 'vision', driving PhotoCaptionHandler's
 * adaptive backoff-then-probe algorithm. Deliberately its own table rather
 * than a Settings value: Settings is admin-configured knobs cached for a
 * whole request, this is machine-written state mutated on every single job
 * run and must never be confused with the admin's own slot assignment.
 */
final class AiRateLimitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{slot: string, providerConfigId: ?int, intervalSeconds: int, consecutiveOk: int, nextAllowedAt: ?string}
     */
    public function getOrCreate(string $slot): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_rate_limit_state WHERE slot = ?');
        $stmt->execute([$slot]);
        $row = $stmt->fetch();

        if ($row === false) {
            $now = gmdate('Y-m-d H:i:s');
            $this->pdo->prepare(
                'INSERT INTO ai_rate_limit_state (slot, provider_config_id, interval_seconds, consecutive_ok, next_allowed_at, updated_at)
                 VALUES (?, NULL, 0, 0, NULL, ?)'
            )->execute([$slot, $now]);
            return [
                'slot' => $slot,
                'providerConfigId' => null,
                'intervalSeconds' => 0,
                'consecutiveOk' => 0,
                'nextAllowedAt' => null,
            ];
        }

        return [
            'slot' => (string) $row['slot'],
            'providerConfigId' => $row['provider_config_id'] !== null ? (int) $row['provider_config_id'] : null,
            'intervalSeconds' => (int) $row['interval_seconds'],
            'consecutiveOk' => (int) $row['consecutive_ok'],
            'nextAllowedAt' => $row['next_allowed_at'],
        ];
    }

    public function update(
        string $slot,
        ?int $providerConfigId,
        int $intervalSeconds,
        int $consecutiveOk,
        ?\DateTimeImmutable $nextAllowedAt,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ai_rate_limit_state
             SET provider_config_id = ?, interval_seconds = ?, consecutive_ok = ?, next_allowed_at = ?, updated_at = ?
             WHERE slot = ?'
        );
        $stmt->execute([
            $providerConfigId,
            $intervalSeconds,
            $consecutiveOk,
            $nextAllowedAt?->format('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s'),
            $slot,
        ]);
    }
}
