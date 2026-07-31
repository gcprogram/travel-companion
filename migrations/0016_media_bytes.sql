-- Byte sizes for quota accounting (original + derivatives combined). Sizes
-- are known at upload/processing time going forward; existing rows get
-- backfilled by a one-shot job enqueued right here (the deploy cron's
-- jobs:work picks it up within a minute) so quota sums are correct from
-- day one without needing filesystem stats per request.
ALTER TABLE photos ADD COLUMN bytes BIGINT UNSIGNED NULL;
ALTER TABLE videos ADD COLUMN bytes BIGINT UNSIGNED NULL;

INSERT INTO jobs (type, payload, status, attempts, max_attempts, run_after, created_at, updated_at)
VALUES ('storage.backfill', '{}', 'pending', 0, 3, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP());
