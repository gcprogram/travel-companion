<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\DayEntryController;
use App\Controller\HomeController;
use App\Controller\LocaleController;
use App\Controller\PhotoController;
use App\Controller\PhotoUploadController;
use App\Controller\TrackController;
use App\Controller\TripController;
use App\Controller\TripMapController;
use App\Controller\VideoController;
use App\Controller\VideoUploadController;
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

        // Track: one per trip, re-upload replaces it (see TrackRepository::replaceForTrip).
        $group->post('/trips/{id:[0-9]+}/track/gpx', [TrackController::class, 'uploadGpx']);
        $group->post('/trips/{id:[0-9]+}/track/trim', [TrackController::class, 'trim']);
        $group->post('/trips/{id:[0-9]+}/track/delete', [TrackController::class, 'delete']);

        // Day entries: creating hangs off the trip, editing/deleting off the entry itself.
        $group->get('/trips/{tripId:[0-9]+}/entries/new', [DayEntryController::class, 'create']);
        $group->post('/trips/{tripId:[0-9]+}/entries', [DayEntryController::class, 'store']);
        $group->get('/entries/{id:[0-9]+}/edit', [DayEntryController::class, 'edit']);
        $group->post('/entries/{id:[0-9]+}', [DayEntryController::class, 'update']);
        $group->post('/entries/{id:[0-9]+}/delete', [DayEntryController::class, 'delete']);

        // Photos: uploading/deleting requires edit rights on the parent entry's trip.
        $group->post('/entries/{entryId:[0-9]+}/photos', [PhotoUploadController::class, 'uploadChunk']);
        $group->post('/photos/{id:[0-9]+}/delete', [PhotoController::class, 'delete']);

        // Videos: same rule, plus a plain-form path for adding a YouTube link.
        $group->post('/entries/{entryId:[0-9]+}/videos', [VideoUploadController::class, 'uploadChunk']);
        $group->post('/entries/{entryId:[0-9]+}/videos/youtube', [VideoUploadController::class, 'addYoutube']);
        $group->post('/videos/{id:[0-9]+}/delete', [VideoController::class, 'delete']);
    })->add(RequireLogin::class);

    $app->get('/trip/{slug}', [TripController::class, 'show']);

    // Same visibility rule as the trip page itself, checked inside the controller.
    $app->get('/trip/{slug}/map', [TripMapController::class, 'show']);
    $app->get('/trip/{slug}/map/data', [TripMapController::class, 'data']);

    // Serving depends on the trip's visibility, not login.
    $app->get('/photos/{id:[0-9]+}/{variant}', [PhotoController::class, 'show']);
    $app->get('/videos/{id:[0-9]+}', [VideoController::class, 'show']);
    $app->get('/videos/{id:[0-9]+}/poster', [VideoController::class, 'poster']);
};
