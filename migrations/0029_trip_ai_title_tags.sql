-- Manuell pflegbare Tags (kommagetrennt, wie poi.categories in den Settings)
-- plus KI-Vorschlaege fuer Titel/Tags - getrennt von title/tags selbst,
-- werden nie automatisch dort hineingeschrieben, nur als Vorschlag
-- angezeigt und per "Uebernehmen" manuell eingefuegt.
ALTER TABLE trips
    ADD COLUMN tags VARCHAR(500) NULL AFTER description,
    ADD COLUMN ai_title_suggestion VARCHAR(190) NULL AFTER tags,
    ADD COLUMN ai_tags_suggestion VARCHAR(500) NULL AFTER ai_title_suggestion;
