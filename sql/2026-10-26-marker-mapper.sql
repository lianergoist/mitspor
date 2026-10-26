-- Markørerne ligger nu i mapper (public/markers/<aktivitet>/<navn>.png),
-- og routes.marker_icon gemmer <mappe>/<navn> (2026-10-26).
-- Ingen skemaændring, kun de gemte værdier oversættes.
-- Kør samtidig med git pull af branchen markoer-mapper. Ukendte værdier
-- giver aktivitetens standardmarkør, så rækkefølgen er ikke kritisk.

UPDATE `routes`
SET `marker_icon` = CASE
    WHEN `marker_icon` LIKE 'neutral\_%'   THEN CONCAT('neutral/', SUBSTRING(`marker_icon`, 9))
    WHEN `marker_icon` LIKE '%1\_v2'       THEN CONCAT(SUBSTRING(`marker_icon`, 1, CHAR_LENGTH(`marker_icon`) - 4), '/v2')
    WHEN `marker_icon` LIKE '%\_retro'     THEN CONCAT(SUBSTRING(`marker_icon`, 1, CHAR_LENGTH(`marker_icon`) - 6), '/retro')
    ELSE CONCAT(`marker_icon`, '/standard')
END
WHERE `marker_icon` IS NOT NULL AND `marker_icon` <> '' AND `marker_icon` NOT LIKE '%/%';
