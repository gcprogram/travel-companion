# Travel Companion

Reisetagebuch & Reiseblog – mobile-first, mit KI-Unterstützung. Läuft auf
Standard-Shared-Hosting (PHP 8.4, MySQL/MariaDB), bewusst ohne WordPress/Joomla.

**Stand:** Phase 1 – Grundsystem mit Benutzerverwaltung und Reisen (siehe
„Roadmap" unten).

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

**Smoke-Test-Checkliste** (das habe ich hier in der Sandbox nicht ausführen
können, da externe Paket-Quellen wie Packagist dort gesperrt sind – jede
Datei ist aber `php -l`-geprüft):

1. `/registrieren` → Konto anlegen (der erste registrierte Benutzer wird
   automatisch `admin`)
2. `/reisen/neu` → Reise mit ein paar Routenstationen anlegen
3. Reise auf der Startseite und unter `/reise/<slug>` ansehen
4. Bearbeiten und Löschen prüfen
5. `/passwort-vergessen` → mit `APP_ENV=development` landet die Mail nur im
   Log (`var/log/app.log`), nicht im Postfach
6. Job-Queue: `php bin/console.php jobs:ping` gefolgt von
   `php bin/console.php jobs:work` – sollte im Log „Ping-Job ausgeführt"
   zeigen

## Deployment auf Bitpalast (Shared Hosting)

1. Composer **lokal** ausführen (`composer install --no-dev -o`) und den
   kompletten Ordner inkl. `vendor/` hochladen – auf vielen Shared-Hosting-
   Umgebungen ist ausgehendes Composer/Git nicht ohne Weiteres nutzbar.
2. Docroot des vhosts auf `public/` zeigen lassen (bei Plesk/cPanel meist als
   „Document Root" einstellbar). Falls das nicht geht: Inhalt von `public/`
   ins Docroot kopieren und in dessen `index.php` den Pfad zu `vendor/`
   anpassen.
3. `.env` auf dem Server anlegen (nicht committen!), `APP_ENV=production`,
   echte DB-Zugangsdaten, `APP_URL=https://deine-domain.tld`.
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
- API-Keys für KI-Provider werden **nicht** im Klartext gespeichert (kommt in
  Phase 5 mit `sodium`-Verschlüsselung).

## Roadmap

1. ✅ Grundsystem: Benutzerverwaltung, Reisen, Job-Queue-Infrastruktur
2. Tagesblogs mit Bild-/Video-Upload (Chunked Upload wegen 20-MB-Limit)
3. Mobile-Optimierung, PWA (Offline, Sync, Icon)
4. Kartenansicht (Leaflet/OSM), EXIF-/GPS-Auswertung
5. KI-Funktionen: Zusammenfassungen, Tags, Sehenswürdigkeiten-Zuordnung,
   Routen-Extraktion aus hochgeladenen Reisebeschreibungen – über
   austauschbare Provider (Anthropic, Gemini, OpenAI-kompatibel, Ollama, …)
6. Erweiterte Suche, intelligente Fotoanalyse
