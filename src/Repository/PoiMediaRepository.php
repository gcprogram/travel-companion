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
}
