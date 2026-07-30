<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Job\PingHandler;
use App\Job\PhotoProcessHandler;
use App\Job\PoiDiscoveryHandler;
use App\Job\VideoProcessHandler;
use App\Job\WeatherFetchHandler;
use App\Job\Worker;
use App\Repository\JobRepository;
use App\Service\PhotoStorage;
use App\Service\VideoStorage;
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

    PhotoStorage::class => static fn (): PhotoStorage => new PhotoStorage(dirname(__DIR__) . '/var/uploads'),
    VideoStorage::class => static fn (): VideoStorage => new VideoStorage(dirname(__DIR__) . '/var/uploads'),

    View::class => static fn (ContainerInterface $c): View => new View(dirname(__DIR__) . '/templates', [
        'csrf' => $c->get(App\Support\Csrf::class),
        'flash' => $c->get(App\Support\Flash::class),
        'appName' => Env::get('APP_NAME', 'Travel Companion'),
    ]),

    Worker::class => static function (ContainerInterface $c): Worker {
        $worker = new Worker($c->get(JobRepository::class), $c->get(LoggerInterface::class));

        // Register all job handlers here. New types: one line per handler.
        $worker->register('demo.ping', $c->get(PingHandler::class));
        $worker->register('weather.fetch', $c->get(WeatherFetchHandler::class));
        $worker->register('photo.process', $c->get(PhotoProcessHandler::class));
        $worker->register('video.process', $c->get(VideoProcessHandler::class));
        $worker->register('poi.discover', $c->get(PoiDiscoveryHandler::class));

        return $worker;
    },
];
