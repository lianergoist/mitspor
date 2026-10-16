-- Valgfri markør pr. rute (2026-10-16).
-- NULL = aktivitetens standardmarkør (activity_types.icon_filename), som før.
-- Ellers et markørsæt i public/markers/ (fx 'kajak' -> kajak1_120x120.png og
-- kajak2_120x120.png). Gyldige værdier står i app/markers.php.

ALTER TABLE `routes`
  ADD COLUMN `marker_icon` varchar(50) DEFAULT NULL AFTER `activity_type_id`;
