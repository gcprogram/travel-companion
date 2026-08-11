-- Free-text location for a diary entry. Manually editable; auto-filled (only
-- while still NULL/empty - never overwrites what the user typed) once a
-- geotagged photo/video is attached to the entry, or from the trip's GPS
-- track if neither exists yet. See EntryLocateHandler.
ALTER TABLE day_entries ADD COLUMN location_name VARCHAR(190) NULL;
