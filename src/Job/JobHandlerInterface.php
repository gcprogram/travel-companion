<?php

declare(strict_types=1);

namespace App\Job;

interface JobHandlerInterface
{
    /**
     * Führt den Job aus. Wirft bei Fehlern eine Exception –
     * der Worker kümmert sich um Retry/Backoff.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
