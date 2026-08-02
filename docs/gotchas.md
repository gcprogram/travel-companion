# Entwicklungs-Hürden & Gotchas

Wiederkehrende Stolpersteine, die Zeit gekostet haben – damit sie nicht nochmal passieren.

---

## Map: Kartenkacheln laden nicht (grau), Track aber sichtbar

**Symptom:** Trip-Karte zeigt nur grauen Hintergrund, Zoom-Buttons und Scale
erscheinen, der GPS-Track wird gezeichnet. Keine Kacheln.

**Root Cause 1 — `MAPTiler_KEY` kommt nicht im Browser an**

Die Live-Seite rendert `data-tile-key=""`. In `public/assets/js/trip-map.js`
steht:

```js
var tileKey = container.dataset.tileKey;
if (tileKey) { L.tileLayer(...).addTo(map); }
```

Bei leerem Key wird die Tile-Layer **gar nicht erst** erzeugt – es geht keine
Tile-Anfrage raus. Der Track erscheint trotzdem, weil er unabhängig vom
Daten-Fetch geladen wird. CSP, Referrer-Policy und MapTiler Allowed-Domains
sind bei leeren Key irrelevant.

Der Key fließt: `MAPTILER_KEY` (Env) → `config/container.php` (`Env::get`)
→ View-Global `mapTilerKey` → Template `data-tile-key="..."`.

**Root Cause 2 — vermutlich veralteter PHP-DI-Container-Cache**

Beobachtet in Produktion: Ein Diagnose-Skript zeigte, dass `.env` korrekt
gelesen wird und `Env::get('MAPTILER_KEY')` den richtigen Wert liefert –
trotzdem blieb `data-tile-key=""` im ausgelieferten HTML leer. Nach
`rm -rf var/cache/*` und einem PHP-Neustart war der Key sofort im HTML da.

Wahrscheinlichste Erklärung: `public/index.php` aktiviert im
Produktionsmodus die PHP-DI-Kompilierung:

```php
$containerBuilder->enableCompilation(dirname(__DIR__) . '/var/cache');
```

Der kompilierte Container in `var/cache/` wird nicht automatisch
invalidiert, wenn sich `.env` oder `config/container.php` ändert. War der
Wert leer, als der Container zuletzt kompiliert wurde, bliebe der
View-Wert leer – auch nachdem `.env` korrigiert wurde. Das passt zum
beobachteten Verhalten, wurde aber nicht durch Vergleich des Cache-Inhalts
selbst verifiziert (kein Datei-Zugriff auf `var/cache/` in der Session).

**Fix:**

```bash
rm -rf var/cache/*
```

Danach lädt der Container neu und `Env::get('MAPTILER_KEY')` greift.

**Warum "Key ist im Plesk-UI gesetzt" nicht reicht:** Die App liest den Key
über `Env::get()`, das zuerst `getenv()` prüft, dann die `.env`-Datei im
Projekt-Root (`dirname(__DIR__) . '/.env'` ab `public/index.php`). Unter
Plesk/nginx+PHP-FPM kommen UI-Environment-Variablen und Apache-`SetEnv` nicht
verlässlich bei `getenv()` an. Nur die `.env`-Datei ist zuverlässig.

**Diagnose-Helfer:** `public/debug-env.php` (temporär, nicht committen!) zeigt
Pfad, Lesbarkeit, `getenv()`-Wert und `Env::get` nach `Env::load`. Nach Test
wieder löschen.

**Vorbeugung:** Nach jeder Änderung an `.env` oder `config/` den Container-
Cache leeren (`rm -rf var/cache/*`). Seit dem Code-Fix gibt es zusätzlich
eine `console.warn` im Browser, wenn der Key leer ist.

---

## MapTiler-Endpoint: `/256/` nicht vergessen

**Symptom:** Key korrekt, Kacheln laden, aber versetzt / mit Lücken.

**Ursache:** MapTilers XYZ-Endpoint ohne `/256/`-Segment liefert 512px-Kacheln.
Leaflet erwartet default 256px → Kacheln landen auf dem falschen Raster.

**Fix:** Explizit `/256/` in der URL:

```js
L.tileLayer('https://api.maptiler.com/maps/openstreetmap/256/{z}/{x}/{y}.jpg?key=' + tileKey, { ... })
```

Siehe [MapTiler Maps API](https://docs.maptiler.com/cloud/api/maps/).

---

## tile.openstreetmap.org direkt nutzen → IP-Block

**Symptom:** Nach einiger Zeit liefert tile.openstreetmap.org keine Kacheln
mehr (403 / IP-Sperre).

**Ursache:** OSM-Usage-Policy verbietet „heavy use" durch echte Apps.

**Fix:** Stets MapTiler (oder vergleichbarer Provider) statt direktem
tile.openstreetmap.org nutzen. Siehe `config/container.php`-Kommentar.
