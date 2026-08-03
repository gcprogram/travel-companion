# CLAUDE.md — Projektkontext Travel Companion

Diese Datei ist die Übergabe an Claude Code. Sie wird bei jeder Session
automatisch gelesen. Bei Architektur-Änderungen bitte mitpflegen.

## Was das ist

Reisetagebuch/Reiseblog-Webapp für Stefan (privat, evtl. später kommerzielle
Anteile). Mobile-first, Nutzung vom Handy aus dem Urlaub. Bewusst **kein**
WordPress/Joomla. Repo: `gcprogram/travel-companion`.

## Zielumgebung (WICHTIG — bestimmt viele Design-Entscheidungen)

Bitpalast Shared Hosting (Plesk), per Git-Deployment mit Deploy-Shell-Befehlen
verbunden:

- PHP 8.4.23 (FPM), MariaDB/MySQL, Composer, Cron („Geplante Aufgaben")
- `memory_limit` 512M, **`upload_max_filesize`/`post_max_size` 20M**,
  **`max_execution_time` 30s**
- **`exec`, `shell_exec`, `proc_open` etc. sind DEAKTIVIERT** → kein ffmpeg,
  keine CLI-Tools aus PHP heraus
- Extensions vorhanden: `pdo_mysql`, `exif`, `gd`, `imagick` (Delegates listen
  Videoformate — Frame-Extraktion ggf. möglich, ungetestet), `curl`, `intl`,
  `mbstring`, `sodium`, `redis`, `sqlite3`, `zip`

Konsequenzen:
- Große Uploads (Videos) → **Chunked Upload** clientseitig, serverseitig
  zusammensetzen
- Alles Langsame (KI, EXIF-Batch, Geocoding, Bildderivate) → **Job-Queue**
  (`jobs`-Tabelle), abgearbeitet vom Minuten-Cron:
  `* * * * * php bin/console.php jobs:work --max-runtime=50`
- Videokompression/-thumbnails **clientseitig** im Browser (WebCodecs/Canvas),
  Alternative: YouTube-Link-Referenz statt Upload

## Architektur & Konventionen

- **Slim 4** nur als Router/Middleware-Kern. Geschäftslogik lebt in
  `src/Service`, Datenzugriff in `src/Repository` (reines PDO, kein ORM).
  Framework muss austauschbar bleiben — keine Slim-Typen in Services/Repos.
- Migrationen: nummerierte `.sql`-Dateien in `/migrations`, MySQL-Dialekt,
  ausgeführt via `php bin/console.php migrate`. **Bestehende Migrationsdateien
  nie ändern**, immer neue anhängen (sie laufen auf dem Server automatisch
  beim Deploy).
- Templates: reine `.php`-Dateien in `/templates`, Escaping mit `e()`,
  Layout via `View`-Klasse. Keine Template-Engine einführen.
- CSS: eine Datei `public/assets/css/app.css`, mobile-first, **kein
  Build-Step, kein npm** — muss direkt auf Shared Hosting laufen.
- JS: eigene, abhängigkeitsfreie Dateien unter `public/assets/js/`. Einzige
  Ausnahme: `public/assets/js/vendor/mp4-muxer.js` (MIT, Lizenz daneben als
  `.txt`) fürs Video-Muxing — als einzelne Datei eingebunden, kein npm/Build.
- Hintergrund-Jobs: `JobHandlerInterface` implementieren, im
  `config/container.php` bei `Worker::register()` eintragen.
- Strict types überall, `final` Klassen. Code (Kommentare, Bezeichner,
  Routen/Endpunkte) durchgehend **Englisch**. UI-Texte sind lokalisiert:
  `lang/en.php` + `lang/de.php` (Keys müssen in beiden Dateien existieren),
  Ausgabe über den globalen `t('key')`-Helfer (analog zu `e()`).
  Spracherkennung: `LocaleMiddleware` liest zuerst das `locale`-Cookie,
  sonst den Accept-Language-Header, Fallback ist **Englisch**
  (`Translator::DEFAULT_LOCALE`). Umschalter: `/lang/{en|de}` setzt das
  Cookie. Neue UI-Strings immer in **beide** Sprachdateien eintragen.
- Qualität vor Rückwärtskompatibilität: sauber refaktorieren ist erwünscht;
  Ausnahme sind die Migrationsdateien (s.o.) und öffentliche URLs (Slugs
  stabil halten).
- Sicherheit: CSRF-Feld (`$csrf->field()`) in jedes POST-Formular; private
  Inhalte für Unberechtigte als 404 (nicht 403); Secrets nur in `.env`
  (nie committen); KI-API-Keys bei Speicherung in DB mit `sodium`
  verschlüsseln.

## Arbeitsweise

- **Jede Iteration endet lauffähig und wird sofort committet** (Git-Push
  deployt auf die Website). Kleine, thematische Commits mit deutscher
  Commit-Message.
- Vor Design-Annahmen, die nicht aus dieser Datei oder dem Code folgen:
  **Stefan fragen**, nicht raten.
- Lokal testen: `composer run serve` (→ 127.0.0.1:8080), Smoke-Test-Checkliste
  im README.
- CI (GitHub Actions) muss grün bleiben: composer install, `php -l`,
  `composer validate`.

## Getroffene Entscheidungen (nicht neu diskutieren)

- Framework: Slim 4 (MIT; gewählt wegen Austauschbarkeit und Lizenz)
- KI-Anbindung: austauschbare Provider-Profile — OpenAI-kompatibler Adapter
  (OpenAI, DeepSeek, Nvidia, OpenRouter, Ollama, Custom) + **nativer**
  Anthropic-Adapter + **nativer** Gemini-Adapter (wegen Vision/Websuche).
  Config je Profil: provider, base_url (Default automatisch, überschreibbar),
  api_key, model (per Fetch befüllbar, überschreibbar), Backup-Profil als
  Fallback bei Fehlern/Rate-Limits. Capability-Flags (Vision, PDF, Websuche).
- Video: clientseitige Kompression + Chunked Upload, alternativ
  YouTube-Referenz (`youtube-nocookie.com`-Embed).
- **GPS-Geotags müssen Kompression/Ableitungen überleben** (wichtig für
  Orte/Sehenswürdigkeiten/Routen, s. Roadmap Punkt 4): bei Fotos bleibt das
  Original mit vollem EXIF unangetastet (nur Thumbnail/Web-Ableitung werden
  `stripImage()`t), zusätzlich liest `PhotoProcessHandler` GPSLatitude/
  GPSLongitude per `exif_read_data()` in eigene `photos.lat`/`lng`-Spalten.
  Bei Videos wird das Original **nicht** aufgehoben (bewusst, wegen
  Speicherplatz) — deshalb liest `video-geotag.js` den `moov > udta > ©xyz`
  ISO-6709-Ort **vor** der Kompression aus dem Container (den sieht die
  Kompression selbst nie, die decodiert nur Pixel/Audio) und schickt ihn im
  Chunked-Upload mit nach `videos.lat`/`lng`. Best-effort in beiden Fällen —
  fehlt der Geotag im Original, bleibt das Feld einfach NULL.
- Karten: Leaflet + OpenStreetMap; Geocoding/POIs über Nominatim/Overpass
  mit Caching. Kartenkacheln laufen über **MapTiler**, nicht direkt über
  `tile.openstreetmap.org` — dessen Nutzungsbedingungen verbieten "heavy use"
  durch echte Apps, das führt früher oder später zur IP-Sperre (ist uns beim
  Testen passiert). MapTiler liefert dieselben OSM-Kartendaten unter einem
  dafür vorgesehenen Plan; Key kommt aus `MAPTILER_KEY` (.env), pro Umgebung
  ein eigener, produktiv per "Allowed HTTP origins" auf `citiontour.com`
  eingeschränkt.
- DB-Schema darf sich mit Features weiterentwickeln (per neuer Migration).
- User-Rollen: `admin` / `manager` / `ai_user` / `user` (ersetzt das alte
  `admin`/`author`/`visitor`-Modell — `author`/`visitor` wurden im Code nie
  unterschieden). Speicher-Quotas pro Rolle (`user`/`ai_user` 50 MB,
  `manager` 500 MB, `admin` unbegrenzt) und KI-Token-Budget/Monat (`ai_user`
  200k, `manager` 1M, Durchsetzung erst mit Phase 6) sind laufzeit-änderbar
  über die `settings`-Tabelle (Admin-UI: `/admin/settings`), mit
  Hardcoded-Fallback in `Settings::DEFAULTS`. Ein Wert in
  `users.storage_quota_override_bytes` schlägt den Rollen-Default immer.
  Quota wird dem **Besitzer der Reise** angelastet, nicht dem Hochladenden.
- Selbstregistrierung: Pflicht-E-Mail-Bestätigung (Token-Muster wie
  Passwort-Reset, kurze TTL aus den Settings), kein Auto-Login mehr. Antwort
  ist absichtlich identisch, ob die E-Mail schon vergeben ist oder nicht
  (Anti-Enumeration). Anti-Abuse: >3 nie bestätigte Versuche/IP in 25h sperrt
  die IP, max. 5 verschiedene E-Mails/IP in 24h, 1 Versuch/E-Mail alle 5 Min.
  Zusätzlicher Settings-Schalter `registration.mode`: `email` (Bestätigung
  reicht) oder `admin_approval` (danach zusätzlich Freigabe-Warteschlange im
  Admin-UI). Jede Registrierung benachrichtigt `ADMIN_EMAIL` (.env) per Job.

## Roadmap (Phase 1–5 sind fertig)

1. ✅ Grundsystem: Auth, Rollen (admin/author/visitor), Reisen mit
   Routen-Stationen, Job-Queue-Infrastruktur
2. ✅ Tagesblogs: Text, Stimmung, Bewertung, GPS, Wetter · Bild-Upload
   (Chunked, Imagick-Derivate Thumbnail/Web als WebP, Speicherung außerhalb
   Webroot, Auslieferung über `PhotoController` mit Rechteprüfung) · Video
   (clientseitige Kompression per WebCodecs + vendorter `mp4-muxer`-Lib,
   Ton inklusive, MP4/H.264/AAC; Browser ohne WebCodecs-Unterstützung
   bekommen den Upload-Button deaktiviert und nutzen die
   YouTube-nocookie-Embed-Alternative; Poster-Frame per Imagick,
   Best-Effort — Video bleibt auch ohne Poster nutzbar) · EXIF-Geotags aus
   Foto/Video werden schon jetzt in eigene `lat`/`lng`-Spalten extrahiert
   (Details unten unter Architektur), damit sie Kompression/Ableitungen
   überleben — die eigentliche Auswertung/Kartendarstellung ist Punkt 4
3. ✅ Mobile-Feinschliff, PWA (Manifest, Service Worker, IndexedDB-Offline-Queue
   mit WLAN-Präferenz, lokales Entwurf-Autosave)
4. ✅ Kartenansicht (`/trip/{slug}/map`, Leaflet + OSM, zweite genehmigte
   JS-Dependency-Ausnahme neben `mp4-muxer`): Foto/Video-Pins mit
   Zoom-abhängigem Icon-Wechsel und Lightbox · GPX-Upload
   (`GpxParser`/`TrackSimplifier`, Douglas-Peucker-Dezimierung) ersetzt die
   einfache Verbindungslinie durch den echten Track, inkl. Trim-Regler ·
   Track-Glättung (`TrackSmoothingService`: Pause-Erkennung >10min,
   Genauigkeits-Glättung, beides read-time, nie persistiert) ·
   Uhrzeit-Tooltips auf Trackpunkten · Track aus lokalem Foto/Video-Ordner
   ohne Upload (`photo-geotag.js`/`video-geotag.js` client-seitig,
   `TrackController::submitPoints`) · POI-Erkennung über die öffentliche
   Overpass-API (`PoiDiscoveryService`, geclustert nach Routen-Sprüngen
   >2km, als Job wegen mehrerer sequenzieller HTTP-Calls), manuelles
   Hinzufügen per Kartenklick · automatische Foto/Video-Zuordnung zu POIs
   nach Nähe (`PoiAssignmentService`, ~150m Radius)
5. ✅ User-Management: neue Rollen `admin`/`manager`/`ai_user`/`user`
   (Details oben unter Entscheidungen) · Speicher- und KI-Token-Quotas pro
   Rolle, laufzeit-konfigurierbar über `/admin/settings` · gehärtete
   Selbstregistrierung mit Pflicht-E-Mail-Bestätigung, IP/E-Mail-Rate-Limits,
   optionalem Admin-Freigabe-Modus, Admin-Benachrichtigung je Registrierung ·
   Admin-Oberfläche `/admin/users`: Stats pro User (Speicher, Anzahl
   Reisen/Einträge/Fotos/Videos/Tracks, Logins), Rollenwechsel,
   Aktivierung/Deaktivierung, Quota-Override, Reise-Transfer zwischen
   Accounts, User-Löschung als Job (räumt dabei auch Dateien auf — behebt
   ein bestehendes Leck: Reise/Eintrag löschen entfernte bisher nur
   DB-Zeilen, nie die zugehörigen Dateien in `var/uploads`; jetzt räumt
   `MediaCleanupService` in beiden Fällen mit auf)
6. **Als Nächstes:** Photo Upload muss auch komprimiert erfolgen (ca. 1/10 der Original-Filesize). Dies kann durch eine Mischung von px-size Verkleinerung und höherer JPG-Komprimierung erolgen. 
   Bug: Die Breite ist bei Portrait & Landscape gleich, 
   damit erscheinen Portrait-Fotos deutlich größer. 
7.  KI-Funktionen (Zusammenfassungen Tag/Reise, Titel, Tags,
   Routen-Extraktion aus PDF/DOCX/Web, Bildbeschreibungen) über die
   Provider-Abstraktion, alles als Queue-Jobs — inkl. der in Runde 4
   bewusst zurückgestellten Textextraktion aus eingefügten
   Reiseveranstalter-Beschreibungen und der generierten Reisebeschreibung
   mit wählbaren "Seelen"-Templates. Hier greift auch erstmals die
   KI-Token-Quota aus Phase 5 (Zählinfrastruktur existiert schon,
   Durchsetzung noch nicht).
8. Geocaching cache_type Symbole, einblenden, auf der Karte wenn an dem Ort in 50 m Umkreis ein Photo gemacht wurde, oder die Bewegungsgeschwindigkeit (Track) Fußgänger oder kleiner ist und man in 50 m Umkreis vorbei kam lt. Track.
9. Erweiterte Suche (Datum/Ort/Freitext/Tags/Personen), Timeline-Ansicht

## Bugs/Glitches
1. Bitpalast Hoster blockt eigene IP unseres Home-Anschlusses (der Server läuft weiter), wegen "fehlerhafter" Anfragen. 
   Herausfinden, warum ein normales Navigieren auf der Seite so etwas auslöst.
2. Upload von GPX-Dateien stackt nicht. Eine Datei ersetzt die andere visuell in der Karte.
   Besser: Alle Tracks werden intern zusammengelegt und können auch wieder gelöscht werden (alle zusammen, man kann es ja wiederholen mit Einzeluploads)
   

## Nützliche Befehle

```bash
composer run serve                                # Dev-Server
php bin/console.php migrate                       # Migrationen anwenden
php bin/console.php jobs:work --max-runtime=50    # Queue abarbeiten
php bin/console.php jobs:ping                     # Test-Job einreihen
```

Lokale Entwicklung läuft inzwischen gegen eine echte lokale MariaDB (nicht nur
gegen PHP eingebauten Server ohne DB) — Zugangsdaten in der lokalen `.env`,
eigener (unbeschränkter) `MAPTILER_KEY` getrennt vom Produktions-Key. In
`APP_ENV=development` werden Mails nur nach `var/log/app.log` geloggt statt
verschickt, praktisch zum Testen von Bestätigungs-/Reset-Links.
