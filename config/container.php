<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Job\PingHandler;
use App\Job\Worker;
use App\Repository\JobRepository;
use App\Support\Env;
use App\Support\View;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    PDO::class => static fn (): PDO => Connection::create(),

    LoggerInterface::class => static function (): LoggerInterface {
        $logger = new Logger('app');
        $level = Env::get('APP_ENV', 'production') === 'development' ? Level::Debug : Level::Info;
        $logger->pushHandler(new StreamHandler(dirname(__DIR__) . '/var/log/app.log', $level));
        return $logger;
    },

    View::class => static fn (ContainerInterface $c): View => new View(dirname(__DIR__) . '/templates', [
        'csrf' => $c->get(App\Support\Csrf::class),
        'flash' => $c->get(App\Support\Flash::class),
        'appName' => Env::get('APP_NAME', 'Travel Companion'),
    ]),

    Worker::class => static function (ContainerInterface $c): Worker {
        $worker = new Worker($c->get(JobRepository::class), $c->get(LoggerInterface::class));

        // Hier alle Job-Handler registrieren. Neue Typen: eine Zeile pro Handler.
        $worker->register('demo.ping', $c->get(PingHandler::class));

        return $worker;
    },
];
