<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\HomeController;
use App\Controller\TripController;
use App\Middleware\RequireLogin;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [HomeController::class, 'index']);

    // Auth
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout']);
    $app->get('/registrieren', [AuthController::class, 'showRegister']);
    $app->post('/registrieren', [AuthController::class, 'register']);
    $app->get('/passwort-vergessen', [AuthController::class, 'showForgot']);
    $app->post('/passwort-vergessen', [AuthController::class, 'forgot']);
    $app->get('/passwort-reset', [AuthController::class, 'showReset']);
    $app->post('/passwort-reset', [AuthController::class, 'reset']);

    // Reisen: Anlegen/Bearbeiten nur angemeldet, Ansehen richtet sich nach visibility
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/reisen/neu', [TripController::class, 'create']);
        $group->post('/reisen', [TripController::class, 'store']);
        $group->get('/reisen/{id:[0-9]+}/bearbeiten', [TripController::class, 'edit']);
        $group->post('/reisen/{id:[0-9]+}', [TripController::class, 'update']);
        $group->post('/reisen/{id:[0-9]+}/loeschen', [TripController::class, 'delete']);
    })->add(RequireLogin::class);

    $app->get('/reise/{slug}', [TripController::class, 'show']);
};
