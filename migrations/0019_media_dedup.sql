-- Content-hash-based dedup, scoped to a single trip (never across trips or
-- users - avoids any cross-user data-linking concern). source_photo_id/
-- source_video_id deliberately has NO foreign key: it identifies which
-- photos/{id}/ (or videos/{id}/) directory actually holds the files, and
-- that id's own row may legitimately be gone while the directory still
-- exists, as long as some other row still points at it - see
-- MediaCleanupService and Photo/VideoController::delete() reference
-- counting. NULL means "this row's own id is the storage id" (i.e. this row
-- owns real files on disk); non-NULL rows own no files of their own at all.
ALTER TABLE photos
    ADD COLUMN content_hash CHAR(64) NULL,
    ADD COLUMN source_photo_id INT UNSIGNED NULL,
    ADD KEY idx_photos_content_hash (content_hash);

ALTER TABLE videos
    ADD COLUMN content_hash CHAR(64) NULL,
    ADD COLUMN source_video_id INT UNSIGNED NULL,
    ADD KEY idx_videos_content_hash (content_hash);
