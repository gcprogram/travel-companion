-- Rename mood enum values to English identifiers; UI text now lives in lang/*.php.
-- Two-step ALTER so existing rows survive: widen the enum, migrate the data, then narrow it.
ALTER TABLE day_entries
    MODIFY COLUMN mood ENUM(
        "sehr_schlecht", "schlecht", "neutral", "gut", "sehr_gut",
        "very_bad", "bad", "good", "very_good"
    ) NULL;

UPDATE day_entries SET mood = "very_bad" WHERE mood = "sehr_schlecht";
UPDATE day_entries SET mood = "bad" WHERE mood = "schlecht";
UPDATE day_entries SET mood = "good" WHERE mood = "gut";
UPDATE day_entries SET mood = "very_good" WHERE mood = "sehr_gut";

ALTER TABLE day_entries
    MODIFY COLUMN mood ENUM("very_bad", "bad", "neutral", "good", "very_good") NULL;
