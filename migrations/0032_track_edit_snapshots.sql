-- Backing store for the "Route editieren" trackpoint editor's Reset button
-- (TrackEditService/TrackEditController). A snapshot is taken once, on the
-- first delete/insert of an editing session, and holds the point set as it
-- stood right before that first edit - Reset restores exactly that, not the
-- original GPX/upload (which the app never keeps around once parsed).
-- One row per track (mirrors trip_tracks' own 1:1-with-trip cardinality) -
-- a second edit in the same session must NOT overwrite this, only the first
-- one creates it (see TrackRepository::hasEditSnapshot/createEditSnapshot).
CREATE TABLE trip_track_edit_snapshots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    track_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_track_edit_snapshots_track (track_id),
    CONSTRAINT fk_track_edit_snapshots_track FOREIGN KEY (track_id) REFERENCES trip_tracks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_track_edit_snapshot_points (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_id INT UNSIGNED NOT NULL,
    seq SMALLINT UNSIGNED NOT NULL,
    lat DECIMAL(9,6) NOT NULL,
    lng DECIMAL(9,6) NOT NULL,
    elevation_m DECIMAL(7,1) NULL,
    recorded_at DATETIME NULL,
    accuracy_m DECIMAL(6,1) NULL,
    PRIMARY KEY (id),
    KEY idx_snapshot_points_snapshot (snapshot_id, seq),
    CONSTRAINT fk_snapshot_points_snapshot FOREIGN KEY (snapshot_id) REFERENCES trip_track_edit_snapshots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
