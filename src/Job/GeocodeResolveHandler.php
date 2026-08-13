<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\GeocodeCacheRepository;
use App\Service\ReverseGeocodingService;

/**
 * Job type "geocode.resolve". Payload: {"lat": float, "lng": float}.
 * Dispatched by TripMapController::detectStays() on a geocode_cache miss, so
 * the map page never calls Nominatim synchronously from a request - it shows
 * whatever's cached (possibly nothing, on the very first view of a new
 * stay) and this job fills the cache in for the next page load. Stores the
 * result even when Nominatim found nothing, so a genuinely unresolvable spot
 * doesn't get re-queried on every subsequent view.
 */
final class GeocodeResolveHandler implements JobHandlerInterface
{
    public function __construct(
        private readonly ReverseGeocodingService $geocoding,
        private readonly GeocodeCacheRepository $cache,
    ) {
    }

    public function handle(array $payload): void
    {
        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($this->cache->find($lat, $lng)['found']) {
            return; // Resolved by an earlier, duplicate job already.
        }

        try {
            $result = $this->geocoding->reverseGeocode($lat, $lng);
        } catch (\Throwable) {
            return; // Leave uncached - worth a retry on the next miss, unlike a genuine "nothing found".
        }

        $this->cache->store($lat, $lng, $result['name'], $result['country']);
    }
}
