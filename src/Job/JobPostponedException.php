<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Thrown by a handler to reschedule itself for later WITHOUT counting as a
 * failed attempt - for a job that isn't broken, just not its turn yet (e.g.
 * PhotoCaptionHandler waiting out a provider's rate limit). Worker::run()
 * treats this distinctly from a real \Throwable: JobRepository::postpone()
 * instead of markFailed(), so pacing can never exhaust max_attempts and
 * fail a job outright.
 */
final class JobPostponedException extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $until)
    {
        parent::__construct('Job postponed until ' . $until->format('Y-m-d H:i:s'));
    }
}
