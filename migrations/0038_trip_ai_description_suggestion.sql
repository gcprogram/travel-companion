-- "Generiere Beschreibung" (AiTripDescriptionService/TripSuggestDescriptionHandler):
-- same "suggest, never auto-write" convention as ai_title_suggestion/
-- ai_tags_suggestion (migration 0029) - shown next to the description
-- field with an "Uebernehmen" button, only copied into the real
-- description column when the user explicitly does that.
ALTER TABLE trips ADD COLUMN ai_description_suggestion TEXT NULL AFTER ai_tags_suggestion;
