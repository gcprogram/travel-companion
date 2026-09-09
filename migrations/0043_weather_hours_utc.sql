-- Anchors each weather-hour row on a real UTC instant (Stefan's find: the
-- old "hour 0-23 of the day" scheme mixed an implicitly-UTC target time
-- with Open-Meteo's timezone=auto per-location local time, silently wrong
-- across a multi-timezone travel day). `hour` is kept as the LOCAL wall-
-- clock hour at this row's own location, display-only from now on -
-- ordering/joining uses observed_at_utc instead.
ALTER TABLE day_entry_weather_hours
    ADD COLUMN observed_at_utc DATETIME NULL AFTER hour,
    ADD COLUMN utc_offset_seconds INT NULL AFTER observed_at_utc,
    ADD COLUMN location_name VARCHAR(190) NULL AFTER utc_offset_seconds,
    ADD KEY idx_weather_hours_observed_at (day_entry_id, observed_at_utc);
