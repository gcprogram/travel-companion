<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * ~111m-grid cache of Nominatim reverse-geocoding results (migration
 * 0020_geocode_cache.sql) - see that migration for why a row's mere
 * existence, not just a non-null name, is the "already looked up" signal.
 */
final class GeocodeCacheRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{found: bool, name: ?string, country: ?string}
     */
    public function find(float $lat, float $lng): array
    {
        [$latRounded, $lngRounded] = $this->round($lat, $lng);
        $stmt = $this->pdo->prepare(
            'SELECT name, country FROM geocode_cache WHERE lat_rounded = ? AND lng_rounded = ?'
        );
        $stmt->execute([$latRounded, $lngRounded]);
        $row = $stmt->fetch();
        return $row === false
            ? ['found' => false, 'name' => null, 'country' => null]
            : ['found' => true, 'name' => $row['name'], 'country' => $row['country']];
    }

    public function store(float $lat, float $lng, ?string $name, ?string $country = null): void
    {
        [$latRounded, $lngRounded] = $this->round($lat, $lng);
        $stmt = $this->pdo->prepare(
            'INSERT INTO geocode_cache (lat_rounded, lng_rounded, name, country, created_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), country = VALUES(country), created_at = VALUES(created_at)'
        );
        $stmt->execute([$latRounded, $lngRounded, $name, $country, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function round(float $lat, float $lng): array
    {
        return [round($lat, 3), round($lng, 3)];
    }
}
