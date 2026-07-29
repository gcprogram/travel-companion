<?php

declare(strict_types=1);

namespace App\Job;

interface JobHandlerInterface
{
    /**
     * Runs the job. Throw an exception on failure –
     * the Worker takes care of retry/backoff.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
