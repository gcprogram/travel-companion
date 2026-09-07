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
     * Atomically reserves the next due job (no double-claiming, even if
     * two workers happen to run in parallel).
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(8));

        // Atomic claim via a conditional UPDATE on exactly one row;
        // the unique token then identifies precisely this job.
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

    /**
     * Reschedules a job for later without spending one of its
     * max_attempts - for a handler that threw JobPostponedException
     * because it isn't the job's turn yet (e.g. waiting out a rate limit),
     * not because anything actually failed. claimNext() already
     * incremented attempts on claim, so this undoes that.
     */
    public function postpone(int $id, \DateTimeImmutable $until): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE jobs SET status = 'pending', run_after = ?, attempts = GREATEST(0, attempts - 1), updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$until->format('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * @return array{pending: int, running: int, done: int, failed: int}
     */
    public function countByBatch(string $batchId): array
    {
        $counts = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        $stmt = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS c FROM jobs
             WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.batch_id')) = ?
             GROUP BY status"
        );
        $stmt->execute([$batchId]);
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * The most recently completed photo.caption jobs of a batch (bounded,
     * not the whole history) - lets bulk-photo-caption.js live-update
     * whichever diary panels happen to be open right now without the
     * response growing unbounded on a trip with hundreds of photos.
     *
     * @return list<int> photo ids
     */
    public function recentDonePhotoIdsByBatch(string $batchId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.photo_id')) AS photo_id FROM jobs
             WHERE status = 'done' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.batch_id')) = ?
             ORDER BY updated_at DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$batchId]);
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * "Abbrechen": drops every not-yet-started job in the batch. A job
     * already claimed/running finishes normally (can't interrupt a
     * mid-flight HTTP call), already-done/failed rows are left as history
     * for the final summary.
     */
    public function cancelPendingByBatch(string $batchId): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM jobs WHERE status = 'pending' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.batch_id')) = ?"
        );
        $stmt->execute([$batchId]);
        return $stmt->rowCount();
    }

    public function markFailed(int $id, int $attempts, int $maxAttempts, string $error): void
    {
        // Retry with backoff before final failure: 2, 4, 8 minutes ...
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
