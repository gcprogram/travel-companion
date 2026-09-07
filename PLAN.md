# PLAN — Travel Companion: Feature-Ideen & offene Punkte

Freies Sammelbecken für neue Ideen und noch offene Punkte - kein
Sprint-Board, keine Reihenfolge nötig. Einfach unten eintragen, wenn dir
etwas einfällt; abhaken/löschen, wenn's umgesetzt ist. Details zu bereits
UMGESETZTEN Features/Bugfixes stehen in HANDOVER.md (chronologisch, "Teil
X Nachtrag Y"), nicht hier.

## Offene Punkte aus bisherigen Sessions

- **Manuelles "Metadaten neu berechnen" für eine bestehende Reise.**
  `trip.metadata_refresh` (date_start/date_end, Land) läuft nur automatisch
  bei bestimmten Auslösern (Foto-/Track-Upload, Tagebucheintrag löschen).
  Für eine Reise, deren Zeitraum aus einem älteren Grund falsch stehen
  geblieben ist (z. B. Reise 24 vor dem Datums-Klemm-Fix, Nachtrag 31),
  gibt es aktuell keinen Weg, den Job ohne einen neuen Upload erneut
  anzustoßen. Ein Button "Metadaten neu berechnen" auf der Bearbeiten-
  Seite wäre der einfache Fix, falls das nochmal vorkommt.

- **DNF-Erkennung im Review-Karussell** (bewusst zurückgestellt beim
  Workflow-Redesign, siehe Plan-Datei der damaligen Session). Ein
  Geocache, der NICHT gefunden wurde (DNF) aber nah an einem erkannten
  Aufenthalt liegt, könnte als dritte "kind"-Kategorie im
  Aufenthalte/Sehenswürdigkeiten-Karussell auftauchen - kleiner trauriger
  Smiley-Badge (~1/3 Icongröße, obere rechte Ecke), rein informativ, kein
  eigener Bestätigen/Ablehnen-Fluss nötig.

## Ideen-Backlog (noch nicht angefangen)

- **Video-Beschreibungen aus Einzelbild-Sampling** (Stefans Ask, optional
  für die Zukunft). c:geo/AI-MediaAnalyzer liefern teils schon Caption-/
  Transkript-Tags fürs ganze Video (XMP/EXIF), aber keine Beschreibung
  einzelner Szenen. Idee: alle x Sekunden (Default x=20) ein Frame
  extrahieren und per Vision-KI beschreiben lassen, um Videos in der
  Tagesbeschreibung so reichhaltig wie Fotos einzubinden. Bis dahin nutzt
  die Tagesbeschreibung weiterhin nur das vorhandene Video-Caption/
  Transkript (aus AI-MediaAnalyzer-Import) und den Video-Ort.

- **Zurückgestellt: Google-Timeline-Datenabgleich für GPS-Lücken.** Die
  eigentliche virtuelle Track-Glättung ist umgesetzt (Nachtrag 39,
  `TrackSmoothingService::filterOutliers()` - nie destruktiv,
  Original-Trackpunkte bleiben unangetastet, Route-editieren zeigt weiter
  die echten Rohdaten). Offen bleibt Stefans Zusatzidee: an Stellen mit
  schlechtem GPS-Empfang könnten Google-Timeline-Daten (WLAN-Ortung
  drinnen) zuverlässiger sein als der Handy-GPS-Tracker - eine
  Quellen-Priorisierung oder ein Datenabgleich für schlecht abgedeckte
  Zeitfenster wäre denkbar, sobald Vergleichsdaten vorliegen. Kein
  akuter Bedarf mehr, da der reine Ausreißer-Filter beim echten Test
  (Moldau-Reise) schon gut funktioniert hat.

- **Optionale Zusatzidee zum Track-Player: Tageslicht-Farbverlauf.**
  Statt einer einzelnen "abgelaufen"-Farbe könnte der Track sich mit dem
  Tageslicht einfärben - heller/kräftiger zum Sonnenhöchststand, dunkler
  Richtung Sonnenauf-/-untergang. Damit wären z. B. 90-Minuten-
  Mittagspausen an einer auffälligen Farbnuance im Track erkennbar. Erst
  mal zurückgestellt (Stefans Einschätzung: unklar, ob die Farbnuancen in
  der Praxis überhaupt sichtbar genug sind) - bei Gefallen als spätere
  Erweiterung der einfachen Zwei-Farben-Lösung oben denkbar, kein Teil
  einer ersten Umsetzung.

*(Hier neue Ideen eintragen, sobald welche kommen.)*

## Format-Vorschlag für neue Einträge

```
- **Kurztitel.** Ein bis zwei Sätze: was, warum, evtl. wie.
```
### KI-Unterstützung
Für die KI-Generierung gibt es folgende Funktionen:

1. ~~KI generiere Fotobeschreibung~~ **Umgesetzt** (Nachtrag 41: Bulk-
   Caption-Batch über die Job-Queue mit adaptivem Rate-Limiting +
   Backup-Modell-Wechsel; Google Gemini als zweiter, nativer Provider-
   Dialekt für Vision/Web-Search). Details in HANDOVER.md.

2. KI generiere Tagebucheintrag
- Nimmt Reisebeschreibung (Mensch)
- Nimmt das Wetter ("Es war ein warmer, sonniger Tag mit Temperaturen von 20-24°C und ...". Bei Wetterumschwung ab einer bestimmten Zeit: "Gegen 16:00 Uhr kam plötzlich ein Gewitter auf...") 
- Nimmt die Stimmungsbewertung: ("Die Stimmung war super...")
- Nimmt bestätigte Sehenswürdigkeiten (inkl. Geocaches)
- Nimmt Personennamen aus Personenbeschreibung
- Nimmt Personennamen aus den Fotos (siehe AI Media Analyzer um das EXIF oder XMP-Tag zu finden)
- Nimmt die Beschreibung der Bilder aus der Fotobeschreibung.
- Versucht aus der Fotobeschreibung eine Handlung/Verb für die Personen abzuleiten, z.B.:
  -- Essen fotographiert: "Wir gingen lecker Mittagessen in <Restaurant>", "... Pizzaessen", "... Kuchen essen", "... Eis essen", "Wir holen uns ein Waffeleis..."
  -- Wilde Tiere fotographiert: "Wir sahen <Tierart>"
  -- Geocache: "Wir fanden den Tradi 'GC12345 Lüneburgs Stolz' in einem Baum"
- Nimmt die Personenbeschreibung und versucht die Personen in Fotos den Beschreibungen zuzuordnen ("Purple hair" -> Christin, "Bald man" -> Stefan)
- Wenn Geocaches in der Nähe der Spur (<50m) sind, dann diese zum Zeitpunkt der nächsten Annäherung erwähnen in Beschreibung. Da matched dann bestimmt auch ein Foto (Petling/Munitionskiste)
 
- Generiert eine längere Beschreibung (2-3 Seiten). Jede Pause und jede Sehenswürdigkeit bekommt mindestens einen Satz.  
- Die Gesamtbeschreibung soll sich flüssig lesen lassen. Falls viele Caches auf engem Raum geloggt wurden (>3 in einem Kilometer Umkreis, in < 40 min), dann werden die Geocaches als Liste aufgeführt. Format: HH:mm GCcode GCname (cache_type).
Pausen werden wieder normal beschrieben.

3. KI generiere Reisebeschreibung: 
- Kürzt die (KI-)Tagebuchbeschreibungen massiv zusammen, damit ein Text von ca. 1/2 Seite herauskommt.

4. Vorschlag für Titel und Tags machen 
- Kürzt KI-Reisebeschreibung auf einen Satz

Titel könnte z.B. lauten "Franzis Umzug von Langen über Darmstadt nach Lüneburg und Geocachen in Lauenburg"
Siehe https://citiontour.com/share/e357a450c7014ba28c9dd72233f7541389501112304e6b45842e3b4448712adf
