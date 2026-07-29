<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\DayEntryController;
use App\Controller\HomeController;
use App\Controller\LocaleController;
use App\Controller\TripController;
use App\Middleware\RequireLogin;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [HomeController::class, 'index']);

    $app->get('/lang/{locale}', [LocaleController::class, 'set']);

    // Auth
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout']);
    $app->get('/register', [AuthController::class, 'showRegister']);
    $app->post('/register', [AuthController::class, 'register']);
    $app->get('/forgot-password', [AuthController::class, 'showForgot']);
    $app->post('/forgot-password', [AuthController::class, 'forgot']);
    $app->get('/reset-password', [AuthController::class, 'showReset']);
    $app->post('/reset-password', [AuthController::class, 'reset']);

    // Trips: create/edit require login, viewing depends on visibility.
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/trips/new', [TripController::class, 'create']);
        $group->post('/trips', [TripController::class, 'store']);
        $group->get('/trips/{id:[0-9]+}/edit', [TripController::class, 'edit']);
        $group->post('/trips/{id:[0-9]+}', [TripController::class, 'update']);
        $group->post('/trips/{id:[0-9]+}/delete', [TripController::class, 'delete']);

        // Day entries: creating hangs off the trip, editing/deleting off the entry itself.
        $group->get('/trips/{tripId:[0-9]+}/entries/new', [DayEntryController::class, 'create']);
        $group->post('/trips/{tripId:[0-9]+}/entries', [DayEntryController::class, 'store']);
        $group->get('/entries/{id:[0-9]+}/edit', [DayEntryController::class, 'edit']);
        $group->post('/entries/{id:[0-9]+}', [DayEntryController::class, 'update']);
        $group->post('/entries/{id:[0-9]+}/delete', [DayEntryController::class, 'delete']);
    })->add(RequireLogin::class);

    $app->get('/trip/{slug}', [TripController::class, 'show']);
};
