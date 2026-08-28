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

*(Hier neue Ideen eintragen, sobald welche kommen.)*

## Format-Vorschlag für neue Einträge

```
- **Kurztitel.** Ein bis zwei Sätze: was, warum, evtl. wie.
```
