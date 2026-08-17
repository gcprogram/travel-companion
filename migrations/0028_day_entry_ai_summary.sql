-- KI-generierte Tages-Zusammenfassung, getrennt vom selbst geschriebenen
-- body - wird nie automatisch dort hineingeschrieben, nur als Vorschlag
-- angezeigt, den man im Editor manuell uebernehmen kann.
ALTER TABLE day_entries ADD COLUMN ai_summary TEXT NULL AFTER body;
