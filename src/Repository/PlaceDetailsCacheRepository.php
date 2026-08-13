<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Google Places "Place Details" cache, keyed by placeId (migration
 * 0022_place_details_cache.sql). A row's existence is the "already looked
 * up" signal - name/address can legitimately be NULL when Google had
 * nothing, same convention as GeocodeCacheRepository.
 */
final class PlaceDetailsCacheRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{found: bool, name: ?string, address: ?string, lat: ?float, lng: ?float}
     */
    public function find(string $placeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM place_details_cache WHERE place_id = ?');
        $stmt->execute([$placeId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['found' => false, 'name' => null, 'address' => null, 'lat' => null, 'lng' => null];
        }
        return [
            'found' => true,
            'name' => $row['name'],
            'address' => $row['address'],
            'lat' => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
        ];
    }

    public function store(string $placeId, ?string $name, ?string $address, ?float $lat, ?float $lng): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO place_details_cache (place_id, name, address, lat, lng, created_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address), lat = VALUES(lat), lng = VALUES(lng), created_at = VALUES(created_at)'
        );
        $stmt->execute([$placeId, $name, $address, $lat, $lng, gmdate('Y-m-d H:i:s')]);
    }
}
