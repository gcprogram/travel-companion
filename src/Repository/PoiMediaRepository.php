<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PoiMediaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isPhotoAssigned(int $photoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trip_poi_photos WHERE photo_id = ? LIMIT 1');
        $stmt->execute([$photoId]);
        return $stmt->fetchColumn() !== false;
    }

    public function isVideoAssigned(int $videoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM trip_poi_videos WHERE video_id = ? LIMIT 1');
        $stmt->execute([$videoId]);
        return $stmt->fetchColumn() !== false;
    }

    public function assignPhoto(int $poiId, int $photoId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO trip_poi_photos (poi_id, photo_id, assigned_by, created_at) VALUES (?, ?, 'auto', ?)"
        );
        $stmt->execute([$poiId, $photoId, gmdate('Y-m-d H:i:s')]);
    }

    public function assignVideo(int $poiId, int $videoId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO trip_poi_videos (poi_id, video_id, assigned_by, created_at) VALUES (?, ?, 'auto', ?)"
        );
        $stmt->execute([$poiId, $videoId, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPhotosForPoi(int $poiId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM photos p
             JOIN trip_poi_photos tpp ON tpp.photo_id = p.id
             WHERE tpp.poi_id = ? AND p.status = \'ready\'
             ORDER BY p.created_at ASC'
        );
        $stmt->execute([$poiId]);
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findVideosForPoi(int $poiId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.* FROM videos v
             JOIN trip_poi_videos tpv ON tpv.video_id = v.id
             WHERE tpv.poi_id = ? AND (v.status = \'ready\' OR v.type = \'youtube\')
             ORDER BY v.created_at ASC'
        );
        $stmt->execute([$poiId]);
        return $stmt->fetchAll();
    }

    /**
     * Reverse of findPhotosForPoi() - which sight/geocache (if any) each of
     * the trip's photos was taken at (PoiAssignmentService, ~150m match),
     * keyed by photo_id. Used to (1) intersperse it between the diary's
     * photos at the point its own photos appear (detailed diary view,
     * Stefan's ask - gc_code/cache_type so a geocache renders with its real
     * icon+code instead of a generic sight card, lat/lng for the area
     * minimap), and (2) show it in the photo lightbox's caption line. One
     * row per photo since a photo is assigned to at most one POI
     * (PoiMediaRepository::assignPhoto() only ever inserts once, see
     * PoiAssignmentService's "already assigned, skip" check).
     *
     * @return array<int, array{id: int, name: string, category: string, gcCode: ?string, cacheType: ?string, lat: float, lng: float}>
     */
    public function findPoiByPhotoForTrip(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tpp.photo_id, poi.id, poi.name, poi.category, poi.gc_code, poi.cache_type, poi.lat, poi.lng
             FROM trip_poi_photos tpp
             JOIN photos ph ON ph.id = tpp.photo_id
             JOIN day_entries e ON e.id = ph.day_entry_id
             JOIN trip_pois poi ON poi.id = tpp.poi_id
             WHERE e.trip_id = ?'
        );
        $stmt->execute([$tripId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['photo_id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'gcCode' => $row['gc_code'] !== null ? (string) $row['gc_code'] : null,
                'cacheType' => $row['cache_type'] !== null ? (string) $row['cache_type'] : null,
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
            ];
        }
        return $result;
    }
}
