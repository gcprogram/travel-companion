-- Metadata AI MediaAnalyzer (Stefan's separate desktop tool) already wrote
-- into a photo/video's own XMP metadata before upload, under its own
-- private "aimedia" XMP namespace (AiMediaXmpReader) - imported once at
-- process time (PhotoProcessHandler/VideoProcessHandler), since the
-- original file is discarded/stripped shortly after for photos. Deliberately
-- NOT importing that tool's own "Landmark" or "Geocache" fields - this
-- app's own Overpass-based sight search (PoiDiscoveryService) and
-- Geocaching-GPX import already cover the same ground with richer,
-- structured data (Stefan's own call: "das ist hier beim travel-companion
-- besser").
--
-- caption/caption_source exist so a later "generate via travel-companion's
-- own vision AI" feature can overwrite an EXIF-imported caption in place
-- (button-triggered, not built yet) while still knowing which source produced
-- the current text.
ALTER TABLE photos
    ADD COLUMN ai_address VARCHAR(255) NULL AFTER lng,
    ADD COLUMN ai_persons VARCHAR(255) NULL AFTER ai_address,
    ADD COLUMN caption TEXT NULL AFTER ai_persons,
    ADD COLUMN caption_source ENUM('exif_import', 'vision_ai') NULL AFTER caption;

ALTER TABLE videos
    ADD COLUMN ai_address VARCHAR(255) NULL AFTER lng,
    ADD COLUMN ai_persons VARCHAR(255) NULL AFTER ai_address,
    ADD COLUMN caption TEXT NULL AFTER ai_persons,
    ADD COLUMN caption_source ENUM('exif_import', 'vision_ai') NULL AFTER caption,
    ADD COLUMN transcript TEXT NULL AFTER caption_source;
