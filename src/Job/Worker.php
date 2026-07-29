<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\JobRepository;
use Psr\Log\LoggerInterface;

/**
 * Arbeitet fällige Jobs ab. Gedacht für den Minuten-Cron auf dem Hosting:
 *
 *   * * * * * php /pfad/zur/app/bin/console.php jobs:work --max-runtime=50
 *
 * --max-runtime hält den Prozess unter dem nächsten Cron-Start; die
 * max_execution_time des Webservers gilt für CLI nicht, wir begrenzen selbst.
 */
final class Worker
{
    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(string $type, JobHandlerInterface $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function run(int $maxRuntimeSeconds = 50): int
    {
        $deadline = time() + $maxRuntimeSeconds;
        $processed = 0;

        while (time() < $deadline) {
            $job = $this->jobs->claimNext();
            if ($job === null) {
                break; // Nichts fällig – Prozess beenden, der nächste Cron übernimmt.
            }

            $id = (int) $job['id'];
            $type = (string) $job['type'];

            try {
                $handler = $this->handlers[$type]
                    ?? throw new \RuntimeException(sprintf('Kein Handler für Job-Typ "%s" registriert.', $type));

                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);

                $handler->handle($payload);
                $this->jobs->markDone($id);
                $this->logger->info('Job erledigt', ['id' => $id, 'type' => $type]);
            } catch (\Throwable $e) {
                $this->jobs->markFailed(
                    $id,
                    (int) $job['attempts'], // claimNext liefert die Zeile nach dem Claim, attempts ist bereits erhöht
                    (int) $job['max_attempts'],
                    $e->getMessage() . "\n" . $e->getTraceAsString(),
                );
                $this->logger->error('Job fehlgeschlagen', ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
            }

            $processed++;
        }

        return $processed;
    }
}
