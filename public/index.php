<?php

declare(strict_types=1);

use App\Support\Env;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require dirname(__DIR__) . '/config/container.php');

if (Env::get('APP_ENV', 'production') === 'production') {
    $containerBuilder->enableCompilation(dirname(__DIR__) . '/var/cache');
}

$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

(require dirname(__DIR__) . '/config/middleware.php')($app);
(require dirname(__DIR__) . '/config/routes.php')($app);

$app->run();
