<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\UserRepository;
use App\Service\MediaCleanupService;
use Psr\Log\LoggerInterface;

/**
 * Job type "user.delete". Payload: {"user_id": int}.
 *
 * The admin action that dispatches this sets is_active=0 immediately
 * (locks the account out right away); the actual deletion is queued
 * because clearing every file across every trip the user owns can take
 * longer than the host's 30s request limit. Files are removed before the
 * DB row so a request that lands mid-job never sees a used-up user with an
 * empty email/name but still-orphaned files. The DB delete itself cascades
 * (trips -> stations/tracks/pois/day_entries -> photos/videos, see the
 * migrations' FK chain), so only the top-level row needs deleting here.
 */
final class UserDeleteHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MediaCleanupService $mediaCleanup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId === 0) {
            return;
        }
        if ($this->users->findById($userId) === null) {
            return; // Already deleted (e.g. a retried/duplicate job).
        }

        $this->mediaCleanup->deleteForUser($userId);
        $this->users->delete($userId);

        $this->logger->info('User deleted', ['user_id' => $userId]);
    }
}
