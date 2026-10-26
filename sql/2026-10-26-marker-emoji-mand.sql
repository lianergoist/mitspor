-- emoji.png er erstattet af emoji_mand.png og emoji_kvinde.png (2026-10-26).
-- Ruter, der havde valgt emoji, får emoji_mand.
-- Kør efter 2026-10-26-marker-mapper.sql.

UPDATE `routes`
SET `marker_icon` = CONCAT(SUBSTRING(`marker_icon`, 1, CHAR_LENGTH(`marker_icon`) - 5), 'emoji_mand')
WHERE `marker_icon` LIKE '%/emoji';
