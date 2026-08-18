-- Merkt sich per "Besuchte Orte pruefen"-Reviewer verworfene Aufenthalte
-- (Aufenthalte selbst sind keine DB-Zeilen, siehe StayDetectionService -
-- ohne diese Tabelle wuerde ein verworfener Aufenthalt beim naechsten
-- Track-Update/Seitenaufruf immer wieder als Kandidat auftauchen).
CREATE TABLE trip_stay_dismissals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id INT UNSIGNED NOT NULL,
    lat_rounded DECIMAL(9,4) NOT NULL,
    lng_rounded DECIMAL(9,4) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trip_stay_dismissals (trip_id, lat_rounded, lng_rounded),
    CONSTRAINT fk_trip_stay_dismissals_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
