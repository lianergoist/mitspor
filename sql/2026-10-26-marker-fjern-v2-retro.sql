-- Markørerne v2 og retro er fjernet fra public/markers/ (2026-10-26).
-- Ruter, der havde valgt dem, sættes tilbage til aktivitetens standard (NULL).
-- Kør efter 2026-10-26-marker-mapper.sql.

UPDATE `routes`
SET `marker_icon` = NULL
WHERE `marker_icon` LIKE '%/v2' OR `marker_icon` LIKE '%/retro';
