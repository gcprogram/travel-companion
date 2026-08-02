# HANDOVER — Travel Companion

Stand: 2026-08-02. Geschrieben unter Zeitdruck (Claude-Wochenlimit fast
erschöpft) für eine andere/neue KI-Session, die hier nahtlos weitermacht.
Nicht committet (wie `CLAUDE.md`) — reines lokales Gedächtnis.

## Akuter offener Punkt — HIER WEITERMACHEN

**Trip-Map-Kacheln (Tiles) fehlen auf Produktion (citiontour.com), obwohl
`MAPTILER_KEY` korrekt in `<Basisverzeichnis>/citiontour.com/.env` steht.**

Verifiziert per Live-Browser-Check auf `https://citiontour.com/trip/.../map`:
`document.getElementById('trip-map').dataset.tileKey` ist dort **leer**
(`""`), obwohl:
- der Pfad zur `.env` stimmt (ein Verzeichnis über `public/`, korrekt),
- die Zeile `MAPTILER_KEY=otUJuL9lITqoveQ8ya7U` ohne Tippfehler/Leerzeichen
  drinsteht,
- MapTiler-seitige "Allowed HTTP Origins" mittlerweile sogar komplett
  entfernt wurden (Stefan hat das getestet — half nicht, also liegt es NICHT
  an MapTiler-Domain-Restriktionen).

**Führender Verdacht (noch nicht verifiziert, weil kein Server-Shell-Zugriff
in dieser Session verfügbar war):** `src/Support/Env.php::get()` prüft
**zuerst** `getenv($key)` und nimmt dessen Wert, *bevor* der aus `.env`
geparste Wert überhaupt zum Zug kommt:

```php
public static function get(string $key, ?string $default = null): ?string
{
    $fromEnv = getenv($key);
    if ($fromEnv !== false) {   // <-- auch ein LEERER String ("") ist "!== false"!
        return $fromEnv;
    }
    return self::$values[$key] ?? $default;
}
```

Wenn auf dem Server (Plesk-Hosting-Einstellungen für die Domain →
PHP-Einstellungen → "Environment Variables", oder eine Apache-`SetEnv`-
Direktive im vhost) irgendwo eine **echte PHP-Umgebungsvariable**
`MAPTILER_KEY` existiert — und sei sie leer oder mit einem alten/falschen
Wert — gewinnt sie IMMER gegen die `.env`-Datei. Das würde exakt zu den
beobachteten Symptomen passen: `.env` ist korrekt, wird aber nie gelesen.

**Nächster Schritt:** Stefan bei Plesk nachsehen lassen:
1. Domain citiontour.com → "PHP-Einstellungen" bzw. "Umgebungsvariablen" —
   gibt es dort einen Eintrag `MAPTILER_KEY`? Falls ja: löschen (oder auf
   den korrekten Wert setzen) und Seite neu laden.
2. Falls nichts gefunden wird: testweise `var_dump(getenv('MAPTILER_KEY'))`
   an einer harmlosen Stelle ausgeben lassen (z. B. kurzzeitig in
   `public/index.php` nach `Env::load(...)`, NICHT committen), um zu sehen,
   ob PHP dort überhaupt einen (ggf. leeren) String statt `false` sieht.
3. Falls doch `.env`-Parsing schuld ist: `Env::load()` ist ein simpler
   Eigenbau-Parser (`src/Support/Env.php`) — bei exotischen Zeichen im Key
   könnte er trotzdem stolpern, aber das wurde mit dem gezeigten Wert nicht
   reproduziert.

**Sobald geklärt:** Zoom-Buttons, Maßstabsleiste und die Karten-Einbettung
auf der Reise-Hauptseite sind bereits fertig und laut Stefan auf Produktion
sichtbar (Punkt 4 unten) — nur die Kacheln fehlen noch.

## Was in dieser Session (2026-08-02) gefixt wurde

Drei Commits, alle gepusht, alle lokal gegen echte MariaDB + echten Browser
(`mcp__Claude_Browser__*`, nicht nur `php -l`) verifiziert:

1. **`b3cba93`** — Karten-Lightbox (`templates/trips/map.php` /
   `public/assets/js/trip-map.js`) war **permanent sichtbar**, nicht nur beim
   Draufklicken. Ursache: `.map-lightbox { display: flex; }` in
   `public/assets/css/app.css` ohne `[hidden]`-Gegenregel — Autoren-CSS
   gewinnt immer gegen die eingebaute `[hidden]{display:none}`-Regel des
   Browsers, egal was das `hidden`-Attribut sagt. Fix: eine Zeile
   `.map-lightbox[hidden] { display: none; }` ergänzt. Das erklärte den
   kompletten Symptom-Cluster, den Stefan gemeldet hatte: Seite "ausgegraut",
   Kacheln unter dem 75%-schwarzen Backdrop nicht mehr erkennbar (nur die
   dunklere Track-Linie schimmerte durch), Schließen-Button ohne sichtbaren
   Effekt.
2. **`ae287c3`** — Zwei unabhängige Bugs zusammen gefixt:
   - `leaflet.css` wurde **nie geladen**, seit die Map-Seite existiert.
     `templates/trips/map.php` setzte `$headExtra` als lokale PHP-Variable
     im Template, aber `View::render()` (`src/Support/View.php`) baut das
     Layout aus dem Daten-Array des *Controllers*, nicht aus Variablen, die
     im Kind-Template lokal gesetzt wurden — die Variable kam im Layout nie
     an. Ergebnis: Zoom-Buttons standen im DOM, waren aber komplett
     unstyled/unpositioniert. Fix: `headExtra` jetzt korrekt aus
     `TripController::show()` und `TripMapController::show()` übergeben,
     tote Zeile aus `map.php` entfernt.
   - Maßstabsleiste fehlte komplett im Code (`L.control.scale()` wurde nie
     aufgerufen) — in `trip-map.js` ergänzt.
   - Karte zusätzlich direkt auf `templates/trips/show.php` eingebettet
     (Stefans Wunsch: Karte soll sofort sichtbar sein, nicht erst nach Klick
     auf "🗺 Karte ansehen"). Die separate `/map`-Seite bleibt für die
     Bearbeitungswerkzeuge (GPX-Upload, Trimmen, POI-Verwaltung) bestehen.

Bestätigt von Stefan (Punkt 4 seiner letzten Nachricht): Zoom +/- und
Maßstab sind jetzt sichtbar auf Produktion, ebenso die Leaflet-Attribution
unten rechts — also lädt `leaflet.css` dort jetzt korrekt.

## Kontext davor (User-Management, fertig, nicht Teil dieser Session)

Phase 1–5 der Roadmap fertig, deployed. Volle Details zur
User-Management-Funktion (6 Iterationen, Rollen/Quotas/Registrierung/
Admin-UI) stehen in `CLAUDE.md` unter "Getroffene Entscheidungen" und in der
Plan-Datei `C:\Users\gregb\.claude\plans\zippy-floating-stroustrup.md`,
falls Details gebraucht werden — hier nicht wiederholt, um Platz zu sparen.

## Lokale Dev-Umgebung

MariaDB läuft, Zugangsdaten in `.env` (`DB_USER=travel_companion`). Server:
`php -S 127.0.0.1:8098 -t public` aus dem Projektverzeichnis. Login:
`test@example.local` — Passwort wurde in dieser Session testweise auf
`TestPass123!` gesetzt (alter Hash war nicht bekannt/gesichert), Rolle admin.
Dev-Server läuft aktuell NICHT im Hintergrund (wurde am Ende der Session
gestoppt) — bei Bedarf neu starten.

## Hinweise für Stefan: Claude-Nutzung/Tokens optimieren

Da das Wochenlimit diese Session limitiert hat, hier konkrete Hebel für
nächstes Mal:

- **Kleinere, fokussierte Sessions statt Marathon-Sessions.** Jeder Bug für
  sich (wie heute: Lightbox-CSS, dann Tiles-Key, getrennt) braucht weniger
  Kontext als "mach die ganze Map fertig" in einem Rutsch mit Rückfragen.
- **Screenshots/Konsolen-Output möglichst gezielt teilen**, nicht ganze
  Seiten wiederholt neu laden lassen — jeder Browser-Tool-Aufruf (Read,
  JS-Exec, Netzwerk-Log) kostet Kontext, besonders bei wiederholtem
  Neuladen zum Debuggen.
- **Bei Produktions-Problemen sofort die exakte URL/den Screenshot geben**
  (wie zuletzt) statt beschreiben — spart mehrere Hin-und-Her-Runden.
- **`CLAUDE.md`/`HANDOVER.md` schlank halten** und bei jeder Session
  überschreiben statt anhängen (wird hier schon so gemacht) — verhindert,
  dass alte, nicht mehr relevante Historie bei jeder neuen Session
  mitgelesen werden muss.
- **Explizit sagen, wenn nur ein Blick/Analyse reicht** ("schau nur nach",
  "keine Änderung nötig") statt implizit vollen Fix+Test+Commit-Zyklus zu
  erwarten — spart die Verifikationsrunden, wenn die nicht gebraucht werden.
- Große autonome Nacht-Sessions (wie die User-Management-Session) sind
  token-effizient pro Feature, aber summieren sich schnell zum Wochenlimit,
  wenn mehrere davon in kurzer Folge laufen — ggf. bewusst über die Woche
  verteilen statt alles auf einmal.

## Diese Datei

Nicht committet (wie `CLAUDE.md`). Bei neuer Arbeit überschreiben statt
danebenlegen.
