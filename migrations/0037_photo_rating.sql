-- "Fav" star rating for the photo lightbox/gallery view (0-5, xmp:Rating
-- convention). Per-row like caption (migration 0035), not per underlying
-- storage file (migration 0019 dedup) - a reference photo starts unrated
-- even if its canonical original was already rated, since a favorite is a
-- personal/contextual call about THIS appearance of the photo, not a
-- property of the file itself.
ALTER TABLE photos ADD COLUMN rating TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER caption_source;
