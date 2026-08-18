<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * ~111m-grid cache of Nominatim reverse-geocoding results, scoped per trip
 * (migration 0020_geocode_cache.sql, trip-scoping added in
 * 0031_geocode_cache_per_trip.sql) - see those migrations for why a row's
 * mere existence, not just a non-null name, is the "already looked up"
 * signal, and why the cache key includes trip_id (a "clear cache" action
 * used to wipe every trip of every user at once).
 */
final class GeocodeCacheRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{found: bool, name: ?string, country: ?string}
     */
    public function find(int $tripId, float $lat, float $lng): array
    {
        [$latRounded, $lngRounded] = $this->round($lat, $lng);
        $stmt = $this->pdo->prepare(
            'SELECT name, country FROM geocode_cache WHERE trip_id = ? AND lat_rounded = ? AND lng_rounded = ?'
        );
        $stmt->execute([$tripId, $latRounded, $lngRounded]);
        $row = $stmt->fetch();
        return $row === false
            ? ['found' => false, 'name' => null, 'country' => null]
            : ['found' => true, 'name' => $row['name'], 'country' => $row['country']];
    }

    public function store(int $tripId, float $lat, float $lng, ?string $name, ?string $country = null): void
    {
        [$latRounded, $lngRounded] = $this->round($lat, $lng);
        $stmt = $this->pdo->prepare(
            'INSERT INTO geocode_cache (trip_id, lat_rounded, lng_rounded, name, country, created_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), country = VALUES(country), created_at = VALUES(created_at)'
        );
        $stmt->execute([$tripId, $latRounded, $lngRounded, $name, $country, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Full wipe across every trip - this cache has no TTL, so it's the only
     * way to make already-cached rows pick up a ReverseGeocodingService
     * logic change (e.g. the pickName()/composeAddress() fix for
     * bare-subdivision names like "Nordend"). Safe: nothing but a
     * re-resolve delay, dispatched automatically on the next cache miss
     * (GeocodeResolveHandler). Kept as the admin-wide fallback alongside
     * clearForTrip() for the common case of just one trip's names being off.
     */
    public function clear(): int
    {
        return $this->pdo->exec('DELETE FROM geocode_cache');
    }

    public function clearForTrip(int $tripId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM geocode_cache WHERE trip_id = ?');
        $stmt->execute([$tripId]);
        return $stmt->rowCount();
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function round(float $lat, float $lng): array
    {
        return [round($lat, 3), round($lng, 3)];
    }
}
