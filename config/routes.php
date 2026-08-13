<?php

declare(strict_types=1);

use App\Controller\AdminSettingsController;
use App\Controller\AdminUserController;
use App\Controller\AuthController;
use App\Controller\DayEntryController;
use App\Controller\HomeController;
use App\Controller\LocaleController;
use App\Controller\PhotoController;
use App\Controller\PhotoUploadController;
use App\Controller\PoiController;
use App\Controller\ServiceWorkerController;
use App\Controller\ShareController;
use App\Controller\TrackController;
use App\Controller\TripController;
use App\Controller\TripMapController;
use App\Controller\VideoController;
use App\Controller\VideoUploadController;
use App\Middleware\RequireAdmin;
use App\Middleware\RequireLogin;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [HomeController::class, 'index']);
    $app->get('/my-trips', [HomeController::class, 'myTrips'])->add(RequireLogin::class);

    $app->get('/lang/{locale}', [LocaleController::class, 'set']);

    // Served by PHP (not statically) so its cache version can be derived
    // from the assets - see ServiceWorkerController.
    $app->get('/sw.js', [ServiceWorkerController::class, 'show']);

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
    $app->get('/confirm-email', [AuthController::class, 'confirmEmail']);

    // Trips: create/edit require login, viewing depends on visibility.
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/trips/new', [TripController::class, 'create']);
        $group->post('/trips', [TripController::class, 'store']);
        $group->get('/trips/{id:[0-9]+}/edit', [TripController::class, 'edit']);
        $group->post('/trips/{id:[0-9]+}', [TripController::class, 'update']);
        $group->post('/trips/{id:[0-9]+}/delete', [TripController::class, 'delete']);

        // Share tokens: owner/admin only, checked directly against the real
        // user inside the controller (never satisfied by a share token itself).
        $group->post('/trips/{id:[0-9]+}/share-tokens', [ShareController::class, 'create']);
        $group->post('/share-tokens/{id:[0-9]+}/delete', [ShareController::class, 'delete']);

        // Track: one per trip, re-upload replaces it (see TrackRepository::replaceForTrip).
        $group->post('/trips/{id:[0-9]+}/track/gpx', [TrackController::class, 'uploadGpx']);
        $group->post('/trips/{id:[0-9]+}/track/points', [TrackController::class, 'submitPoints']);
        $group->post('/trips/{id:[0-9]+}/track/trim', [TrackController::class, 'trim']);
        $group->post('/trips/{id:[0-9]+}/track/delete', [TrackController::class, 'delete']);

        // POIs: discovery dispatches a job, everything else is direct CRUD.
        $group->post('/trips/{id:[0-9]+}/pois/discover', [PoiController::class, 'discover']);
        $group->post('/trips/{id:[0-9]+}/pois/prune', [PoiController::class, 'deleteUnphotographed']);
        $group->post('/trips/{id:[0-9]+}/pois/stay', [PoiController::class, 'addStay']);
        $group->post('/trips/{id:[0-9]+}/pois', [PoiController::class, 'store']);
        $group->post('/pois/{id:[0-9]+}/visited', [PoiController::class, 'toggleVisited']);
        $group->post('/pois/{id:[0-9]+}/delete', [PoiController::class, 'delete']);

        // Day entries: creating hangs off the trip, editing/deleting off the entry itself.
        $group->get('/trips/{tripId:[0-9]+}/entries/new', [DayEntryController::class, 'create']);
        $group->post('/trips/{tripId:[0-9]+}/entries', [DayEntryController::class, 'store']);
        $group->get('/entries/{id:[0-9]+}/edit', [DayEntryController::class, 'edit']);
        $group->get('/entries/{id:[0-9]+}/media-status', [DayEntryController::class, 'mediaStatus']);
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

    // Admin area: RequireAdmin alone gates it (a null/non-admin user 404s,
    // so no need to also stack RequireLogin on top).
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('/users', [AdminUserController::class, 'index']);
        $group->get('/users/new', [AdminUserController::class, 'showCreate']);
        $group->post('/users', [AdminUserController::class, 'store']);
        $group->post('/users/{id:[0-9]+}/role', [AdminUserController::class, 'setRole']);
        $group->post('/users/{id:[0-9]+}/approve', [AdminUserController::class, 'approve']);
        $group->post('/users/{id:[0-9]+}/active', [AdminUserController::class, 'setActive']);
        $group->post('/users/{id:[0-9]+}/quota', [AdminUserController::class, 'setQuota']);
        $group->post('/users/{id:[0-9]+}/transfer', [AdminUserController::class, 'transfer']);
        $group->post('/users/{id:[0-9]+}/delete', [AdminUserController::class, 'delete']);
        $group->get('/settings', [AdminSettingsController::class, 'show']);
        $group->post('/settings', [AdminSettingsController::class, 'save']);
    })->add(RequireAdmin::class);

    // Public: sets the share_access cookie (ShareAccessCookie) and redirects
    // to the trip. No RequireLogin - that's the whole point of a share link.
    $app->get('/share/{token}', [ShareController::class, 'redeem']);

    $app->get('/trip/{slug}', [TripController::class, 'show']);

    // Same visibility rule as the trip page itself, checked inside the
    // controller - lets the collapsed diary accordion lazy-load an entry's
    // body/photos/videos on first expand instead of shipping them all upfront.
    $app->get('/entries/{id:[0-9]+}/panel', [DayEntryController::class, 'panel']);

    // Deliberately outside the RequireLogin group below: that group also
    // lets a share-token grant through, but a star rating needs a genuine
    // login (checked inside the controller against the real 'user'
    // attribute) - a share-link visitor isn't a "member" for this purpose.
    $app->post('/entries/{id:[0-9]+}/rate', [DayEntryController::class, 'rate']);

    // Same visibility rule as the trip page itself, checked inside the controller.
    $app->get('/trip/{slug}/map', [TripMapController::class, 'show']);
    $app->get('/trip/{slug}/map/data', [TripMapController::class, 'data']);
    $app->get('/trip/{slug}/pois', [PoiController::class, 'index']);

    // Serving depends on the trip's visibility, not login.
    $app->get('/photos/{id:[0-9]+}/{variant}', [PhotoController::class, 'show']);
    $app->get('/videos/{id:[0-9]+}', [VideoController::class, 'show']);
    $app->get('/videos/{id:[0-9]+}/poster', [VideoController::class, 'poster']);
};
