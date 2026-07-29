<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class JobRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $type, array $payload, ?\DateTimeImmutable $runAfter = null): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (type, payload, status, attempts, run_after, created_at, updated_at)
             VALUES (?, ?, \'pending\', 0, ?, ?, ?)'
        );
        $stmt->execute([
            $type,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ($runAfter ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            $now,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Nächsten fälligen Job atomar reservieren (kein doppelter Zugriff,
     * auch wenn zwei Worker parallel laufen sollten).
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(8));

        // Atomarer Claim per bedingtem UPDATE auf genau eine Zeile;
        // das eindeutige Token identifiziert anschließend exakt diesen Job.
        $update = $this->pdo->prepare(
            "UPDATE jobs SET status = 'running', attempts = attempts + 1, claim_token = ?, updated_at = ?
             WHERE status = 'pending' AND run_after <= ?
             ORDER BY id ASC LIMIT 1"
        );
        $update->execute([$token, $now, $now]);

        if ($update->rowCount() === 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE claim_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function markDone(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET status = 'done', updated_at = ? WHERE id = ?");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    public function markFailed(int $id, int $attempts, int $maxAttempts, string $error): void
    {
        // Vor endgültigem Scheitern mit Backoff erneut versuchen: 2, 4, 8 Minuten ...
        if ($attempts < $maxAttempts) {
            $delayMinutes = 2 ** $attempts;
            $stmt = $this->pdo->prepare(
                "UPDATE jobs SET status = 'pending', run_after = ?, last_error = ?, updated_at = ? WHERE id = ?"
            );
            $stmt->execute([
                gmdate('Y-m-d H:i:s', time() + $delayMinutes * 60),
                mb_substr($error, 0, 60000),
                gmdate('Y-m-d H:i:s'),
                $id,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE jobs SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([mb_substr($error, 0, 60000), gmdate('Y-m-d H:i:s'), $id]);
    }
}
