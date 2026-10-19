-- Hastighedstjekket ved upload er fjernet, og kolonnen bruges ikke længere.
ALTER TABLE activity_types DROP COLUMN max_plausible_speed_kmh;
