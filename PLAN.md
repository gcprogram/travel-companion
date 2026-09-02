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

- **Kleine Doku-Korrektur**: der Kommentar bei `ai.slot.vision` in
  `src/Service/Settings.php` beschreibt die Bildbeschreibungs-Funktion
  noch als "geplant, noch nicht gebaut" - ist aber seit Nachtrag 12
  (`AiVisionCaptionService`) längst umgesetzt. Beim nächsten Anfassen der
  Datei den Kommentar korrigieren/entfernen.

## Ideen-Backlog (noch nicht angefangen)

- **Track-Player.** Ein Play-Button unter der Karte, der die Route/den
  Track zeitlich abspielt, in sinnvoller Geschwindigkeit.

  *Machbarkeits-Check (2026-08-28):* grundsätzlich machbar, aber drei
  Bausteine fehlen komplett im Code und müssten neu gebaut werden -
  sanftes Kamera-Folgen (`flyTo`, gibt's aktuell nirgends, Route-editieren
  springt nur hart), eine performante Animationsschleife (die Hauptkarte
  lädt aktuell ALLE Rohpunkte ohne serverseitige Reduzierung - bei
  mehrtägigen Reisen leicht mehrere Tausend Punkte, ein Marker pro Punkt
  wäre zu langsam) und eine eigene "abgelaufen"-Trackfarbe. Das Cursor-
  Konzept (aktueller Punkt, </>-Navigation, Zeitanzeige) aus
  Route-editieren (Nachtrag 26) ist als PATTERN übertragbar, aber nicht
  als Code, da die Route-Edit-Karte keine Fotos/POIs/Lightbox kennt - die
  Umsetzung müsste in trip-map.js passieren, wo das alles schon existiert
  (Layer-Checkboxen, geteilte Lightbox `window.openTripPhotoLightbox`).

  **Geschwindigkeit/Zeitraffer** (nach Rückfrage geklärt): Trackpunkte
  werden mit ca. 1:60 abgespielt (1 min real = 1 sec Abspielzeit).
  - Punktabstand < 1 min: direkt zum nächsten Punkt springen (kein
    sanftes Interpolieren nötig, die Punkte liegen eh schon nah genug
    beieinander).
  - Punktabstand 1-29 min: 1 sec auf dem Punkt verharren.
  - Punktabstand ≥ 30 min (z. B. Flug mit nur Start-/Zielpunkt): über
    diese Lücke sanft interpolieren/animieren (`flyTo`) - hier macht die
    Kamerafahrt tatsächlich Sinn, weil sonst ein harter Sprung entstünde.
    Die Dichte der interpolierten Zwischenschritte muss sich an die
    gerade laufende Abspielgeschwindigkeit anpassen: bei geraffter Zeit
    (viel reale Zeit in 1 sec Abspielzeit) weniger Zwischenschritte pro
    Zeiteinheit als bei normalem Tempo, sonst wirkt die Animation entweder
    ruckelig oder unnötig fein aufgelöst.
  - Alle drei Sekunden-/Minuten-Schwellen im Admin-Bereich konfigurierbar
    (gleiches Muster wie das kürzlich gebaute `ai.description_max_tokens`
    -Setting). Zoom passt sich an die Schrittgröße an.
  - Aufenthalte ohne Fotos sowie offensichtliche Übernachtungen auch
    schnell überspringen (wie ≥30-min-Lücken), unabhängig von der reinen
    Punktdichte.

  **Farben** (nach Rückfrage geklärt): der bereits abgelaufene Track-Teil
  bekommt eine eigene Farbe (z. B. Orange), der noch bevorstehende Teil
  bleibt in der normalen Trackfarbe (Grün) - beide Farben im
  Admin-Bereich wählbar (2 Farbwähler). WICHTIG dabei gleich mit
  korrigieren: die Tagesansicht (aufgeklappter Tagebucheintrag) rendert
  den Tages-Track aktuell fälschlich in genau diesem Orange
  (`dayRouteLine`, trip-map.js) - das ist kein Wahrnehmungsfehler,
  sondern eine echte Doppelbelegung der Farbe. Die Tagesansicht soll
  stattdessen dieselbe Farbe wie der Gesamttrack benutzen, damit Orange
  im Track-Player wirklich nur "schon abgespielt" bedeutet.

  **Exit-Verhalten** (nach Rückfrage geklärt): echte Verlinkung zur
  Route-editieren-Seite, nicht nur eine interne Merkposition - im
  Schritt-Modus fallen Fehler/Ungenauigkeiten im Track auf, die man dann
  direkt editieren können soll. Da Route-editieren Bearbeitungsrechte
  braucht, aber der Player selbst laut nächstem Punkt auch für
  Betrachter ohne Bearbeitungsrechte sichtbar ist, muss dieser
  "zum Editieren springen"-Button/Link nur erscheinen, wenn der aktuelle
  Betrachter die Reise auch bearbeiten darf (gleiche Prüfung wie sonst
  auf der Karte, z. B. die vorhandenen `canEdit`-Gates).

  **Sichtbarkeit** (nach Rückfrage geklärt): auch auf der
  öffentlichen/geteilten Ansicht, nicht nur für den Besitzer - jeder
  Betrachter kann die Reise abspielen, nur der "zum Editieren
  springen"-Teil bleibt bearbeitungsrechte-gebunden (siehe oben).

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
