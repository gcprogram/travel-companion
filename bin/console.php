<?php

declare(strict_types=1);

/**
 * CLI-Einstiegspunkt.
 *
 *   php bin/console.php migrate            Ausstehende Migrationen anwenden
 *   php bin/console.php jobs:work          Fällige Jobs abarbeiten (für den Minuten-Cron)
 *       Optionen: --max-runtime=50         Sekundenlimit pro Lauf
 *   php bin/console.php jobs:ping          Test-Job in die Queue legen
 */

use App\Database\Migrator;
use App\Job\Worker;
use App\Repository\JobRepository;
use App\Support\Env;
use DI\ContainerBuilder;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require dirname(__DIR__) . '/config/container.php');
$container = $containerBuilder->build();

$command = $argv[1] ?? 'help';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}

switch ($command) {
    case 'migrate':
        $migrator = new Migrator($container->get(PDO::class), dirname(__DIR__) . '/migrations');
        $ran = $migrator->migrate();
        echo $ran === []
            ? "Keine neuen Migrationen.\n"
            : 'Angewendet: ' . implode(', ', $ran) . "\n";
        break;

    case 'jobs:work':
        $maxRuntime = max(1, (int) ($options['max-runtime'] ?? 50));
        $processed = $container->get(Worker::class)->run($maxRuntime);
        echo "Jobs verarbeitet: {$processed}\n";
        break;

    case 'jobs:ping':
        $id = $container->get(JobRepository::class)->dispatch('demo.ping', ['hello' => 'world']);
        echo "Ping-Job #{$id} eingereiht. Abarbeiten mit: php bin/console.php jobs:work\n";
        break;

    default:
        echo "Befehle: migrate | jobs:work [--max-runtime=50] | jobs:ping\n";
        exit($command === 'help' ? 0 : 1);
}
