<?php

declare(strict_types=1);

/**
 * CLI entry point.
 *
 *   php bin/console.php migrate            Apply pending migrations
 *   php bin/console.php jobs:work          Work off due jobs (for the minute-cron)
 *       Options: --max-runtime=50          Second limit per run
 *   php bin/console.php jobs:ping          Enqueue a test job
 *   php bin/console.php geocode:clear-cache
 *       Wipes the reverse-geocode cache (geocode_cache) so already-cached
 *       place names get re-resolved with current logic on next view -
 *       run this after a ReverseGeocodingService change to fix names that
 *       were cached before the fix (they don't refresh on their own, see
 *       GeocodeCacheRepository).
 */

use App\Database\Migrator;
use App\Job\Worker;
use App\Repository\GeocodeCacheRepository;
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
            ? "No new migrations.\n"
            : 'Applied: ' . implode(', ', $ran) . "\n";
        break;

    case 'jobs:work':
        $maxRuntime = max(1, (int) ($options['max-runtime'] ?? 50));
        $processed = $container->get(Worker::class)->run($maxRuntime);
        echo "Jobs processed: {$processed}\n";
        break;

    case 'jobs:ping':
        $id = $container->get(JobRepository::class)->dispatch('demo.ping', ['hello' => 'world']);
        echo "Ping job #{$id} enqueued. Work it off with: php bin/console.php jobs:work\n";
        break;

    case 'geocode:clear-cache':
        $removed = $container->get(GeocodeCacheRepository::class)->clear();
        echo "Cleared {$removed} cached geocode result(s). Names re-resolve on next view (via geocode.resolve jobs).\n";
        break;

    default:
        echo "Commands: migrate | jobs:work [--max-runtime=50] | jobs:ping | geocode:clear-cache\n";
        exit($command === 'help' ? 0 : 1);
}
