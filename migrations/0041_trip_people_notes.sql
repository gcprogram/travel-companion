-- Owner-only "who's in the photos" notes (Stefan's ask), one
-- "Name=description" pair per line (e.g. "Christin=woman with purple
-- hair"). Shown only in the edit form, never to viewers - fed into the
-- vision-caption AI prompt (AiVisionCaptionService) so it can name people
-- it can confidently match instead of writing "a person"/"a woman".
ALTER TABLE trips ADD COLUMN people_notes TEXT NULL AFTER tags;
