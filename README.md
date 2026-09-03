# Travel Companion

Reisetagebuch & Reiseblog – mobile-first, mit KI-Unterstützung. Läuft auf
Standard-Shared-Hosting (PHP 8.4, MySQL/MariaDB), bewusst ohne WordPress/Joomla.

**Stand:** in aktivem Einsatz, laufend weiterentwickelt (siehe „Funktionsumfang“
unten). Offene Ideen/Baustellen in `PLAN.md`.

## Funktionsumfang

- **Reisetagebuch**: ein Eintrag pro Tag mit Text, Stimmung, automatisch
  abgerufenem Wetter (Tag/Nacht kompakt in der Übersicht), Bewertung durch
  Betrachter.
- **Fotos & Videos**: Chunked Upload, WebP-Ableitungen (Thumbnail/Web),
  clientseitige Videokompression (WebCodecs) mit YouTube-Link als
  Alternative für Browser ohne WebCodecs-Unterstützung. Lightbox mit
  Zuschneiden, Drehen, Bewertung ("Fav"-Sterne), Diashow und Pfeiltasten-
  Navigation. GPS-Geotags aus EXIF/Videocontainer überleben Kompression.
- **Interaktive Karte** (Leaflet/OpenStreetMap über MapTiler): GPS-Track aus
  GPX-Upload, Google-Timeline-Export oder automatisch aus geotaggten
  Fotos/Videos, mit Glättung/Pause-Erkennung. Eigene Route-editieren-Seite
  für Trackpunkt-Chirurgie (löschen/einfügen/verschieben, Undo/Reset).
  **Track-Player**: spielt die Route zeitlich ab (konfigurierbares Tempo je
  Punktdichte, automatische Kamerafahrt über große Lücken), hält
  automatisch bei Fotos und Sehenswürdigkeiten/Geocaches an.
- **Sehenswürdigkeiten & Geocaching**: automatische POI-Erkennung entlang
  der Route (Overpass API), manuelles Hinzufügen per Kartenklick,
  Geocaching-GPX-/Pocket-Query-Import (gefundene Caches und DNFs mit
  echtem `cache_type`-Icon, Field-Notes-Abgleich), Übersetzung fremdsprachiger
  Namen. Review-Karussell zum Bestätigen/Ablehnen erkannter Aufenthalte und
  Sehenswürdigkeiten.
- **KI-Funktionen** (austauschbare Provider-Profile: OpenAI-kompatibel,
  Anthropic, Gemini, Ollama, …): Tages-Zusammenfassungen, Titel-/Tag-
  Vorschläge, ganze Reise-Überblicke und ausführliche Tagesbeschreibungen,
  Bildbeschreibungen per Vision-Modell, Übersetzungs-Fallback. Jeder
  KI-Vorschlag muss aktiv übernommen werden, nie automatisches Überschreiben.
- **Teilen & Zugriff**: privat / nur Mitglieder / öffentlich, dazu
  widerrufbare Freigabe-Links ("Nur ansehen" oder "Bearbeiten") ohne
  Login-Zwang für die eingeladene Person.
- **Nutzerverwaltung**: Rollen (`admin`/`manager`/`ai_user`/`user`) mit
  Speicher- und KI-Token-Kontingenten, gehärtete Selbstregistrierung
  (E-Mail-Bestätigung, IP-Rate-Limits, optionale Admin-Freigabe),
  Admin-Oberfläche mit Nutzungsstatistiken, Rollenwechsel, Reise-Transfer.
- **Mobile & offline**: PWA (Homescreen-Installation, Service Worker),
  Entwürfe werden offline zwischengespeichert und synchronisiert, sobald
  wieder eine Verbindung besteht.
- **MCP-Zugriff für den eigenen KI-Agenten** (`/mcp`, `/account/mcp-tokens`):
  persönliches, widerrufbares API-Token für einen KI-Agenten, der eigene
  Reisen lesen und Tagebuchtext/Fotos eintragen kann - z. B. einen
  unterwegs diktierten Teilbericht in den bestehenden Tageseintrag
  einfügen, statt ihn zu ersetzen.

## Architektur in Kürze

- **Slim 4** als schlanker Router/Middleware-Kern (MIT-Lizenz). Die
  Geschäftslogik liegt in eigenen `Service`- und `Repository`-Klassen, nicht
  im Framework – Slim lässt sich bei Bedarf austauschen, ohne den Rest
  anzufassen.
- **PDO** direkt, kein ORM. Migrationen sind einfache `.sql`-Dateien in
  `/migrations`, ausgeführt über `bin/console.php migrate`.
- **Job-Queue** (Tabelle `jobs`) für alles, was länger als ein paar Sekunden
  dauert (KI-Aufrufe, Bildanalyse, Videoverarbeitung) – nötig, weil das
  Hosting `max_execution_time = 30s` und `exec/shell_exec` deaktiviert hat.
  Ein Cronjob ruft minütlich `bin/console.php jobs:work` auf.
- **Eigene Templates** (`.php`-Dateien unter `/templates`) statt Templating-
  Engine – eine Dependency weniger auf Shared Hosting.

## Voraussetzungen

- PHP ≥ 8.3 (Ziel-Hosting: 8.4.23) mit den Extensions: `pdo_mysql`, `mbstring`,
  `json`, `curl`, `exif`, `gd`, `imagick`, `intl`, `sodium`
- Composer
- MySQL/MariaDB
- Für den lokalen Test reicht der eingebaute PHP-Webserver, kein Apache/Nginx
  nötig.

## Lokal einrichten

```bash
git clone https://github.com/gcprogram/travel-companion.git
cd travel-companion
composer install

cp .env.example .env
# .env öffnen und mindestens DB_DSN/DB_USER/DB_PASS eintragen.
# Für einen ersten Test reicht eine leere lokale MySQL/MariaDB-Datenbank:
#   CREATE DATABASE travel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

php bin/console.php migrate
composer run serve
# -> http://127.0.0.1:8080
```

**Smoke-Test-Checkliste** (Basis-Sanity-Check, kein vollständiger Test des
mittlerweile deutlich gewachsenen Funktionsumfangs oben):

1. `/register` → Konto anlegen (der erste registrierte Benutzer wird
   automatisch `admin`)
2. `/trips/new` → Reise anlegen
3. Reise auf der Startseite und unter `/trip/<slug>` ansehen
4. Bearbeiten und Löschen prüfen
5. `/forgot-password` → mit `APP_ENV=development` landet die Mail nur im
   Log (`var/log/app.log`), nicht im Postfach
6. Job-Queue: `php bin/console.php jobs:ping` gefolgt von
   `php bin/console.php jobs:work` – sollte im Log „Ping job executed"
   zeigen
7. Sprachumschalter (EN/DE) oben rechts prüfen; UI-Texte sollten sich
   komplett übersetzen
8. Auf einem gespeicherten Tagebucheintrag ein Foto hochladen, danach
   `php bin/console.php jobs:work` laufen lassen – Thumbnail sollte
   anschließend erscheinen; Löschen prüfen (löscht auch die Dateien unter
   `var/uploads/photos/<id>/`)
9. Ein kurzes Video (< 2 Minuten) hochladen – läuft komplett im Browser
   (Kompression + Chunked Upload), sollte ohne Warten auf den Worker sofort
   abspielbar sein; danach `jobs:work` laufen lassen und prüfen, ob ein
   Poster-Thumbnail erscheint. Zusätzlich einen YouTube-Link hinzufügen und
   das Embed prüfen.
10. Geotag-Erhalt: ein Foto/Video mit GPS-Metadaten hochladen, danach in der
    DB `SELECT lat, lng FROM photos`/`videos` prüfen – sollte befüllt sein.
11. Track & Karte: eine GPX-Datei hochladen (`/trip/<slug>/map`), Track sollte
    erscheinen; Track-Player starten und prüfen, dass die Wiedergabe läuft
    und bei einem geotaggten Foto automatisch anhält.

## Deployment auf Bitpalast (Shared Hosting)

1. Composer **lokal** ausführen (`composer install --no-dev -o`) und den
   kompletten Ordner inkl. `vendor/` hochladen – auf vielen Shared-Hosting-
   Umgebungen ist ausgehendes Composer/Git nicht ohne Weiteres nutzbar.
   Ein normaler `git pull` auf dem Server (Plesk-Git-Panel) reicht für alles
   außer `vendor/` und Änderungen an `composer.json`/`composer.lock` – die
   müssen weiterhin manuell per Zip hochgeladen werden.
2. Docroot des vhosts auf `public/` zeigen lassen (bei Plesk/cPanel meist als
   „Document Root" einstellbar). Falls das nicht geht: Inhalt von `public/`
   ins Docroot kopieren und in dessen `index.php` den Pfad zu `vendor/`
   anpassen.
3. `.env` auf dem Server anlegen (nicht committen!), `APP_ENV=production`,
   echte DB-Zugangsdaten, `APP_URL=https://deine-domain.tld`, `APP_KEY`
   (Secret-Verschlüsselung, siehe „Sicherheitsrelevantes“) sowie
   `MAPTILER_KEY` (Kartenkacheln laufen bewusst über MapTiler statt direkt
   über `tile.openstreetmap.org`, dessen Nutzungsbedingungen echte Apps mit
   nennenswertem Traffic ausschließen).
4. `php bin/console.php migrate` einmalig über SSH oder eine geschützte
   CLI-Konsole im Hosting-Panel ausführen.
5. Scheduled Task/Cron einrichten:
   ```
   * * * * * php /pfad/zur/app/bin/console.php jobs:work --max-runtime=50
   ```

## Sicherheitsrelevantes

- Passwörter: `password_hash`/`password_verify`, automatisches Rehashing bei
  Algorithmus-Updates.
- CSRF-Token auf jedem Formular, geprüft in `CsrfMiddleware`.
- Session-Fixation: `session_regenerate_id()` bei Login/Logout.
- Private Reisen sind für Fremde nicht von „existiert nicht" unterscheidbar
  (404 statt 403).
- API-Keys für KI-Provider werden nie im Klartext gespeichert – Verschlüsselung
  bei Ablage in der `settings`-Tabelle über `sodium_crypto_secretbox`, Schlüssel
  aus `APP_KEY` (`.env`, nie committen).

## Weiterführende Dokumentation

- `PLAN.md` – freies Sammelbecken für neue Ideen und noch offene Punkte
  (Teil dieses Repos).
- `HANDOVER.md` – chronologisches Protokoll bereits umgesetzter Features und
  Bugfixes, laufend fortgeschrieben (lokal, bewusst nicht Teil dieses Repos
  – `.gitignore`).
