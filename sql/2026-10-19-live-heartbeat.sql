-- 2026-10-19: Livstegn fra appen (branch live-heartbeat)
-- Køres på dev (tracks_dev) og derefter på produktion (tracks), FØR git pull.
--
-- last_contact_at: hvornår appen sidst var i kontakt om etapen (også uden
-- nye punkter). Livesiden kan så skelne "står stille" fra "ingen kontakt".

ALTER TABLE `live_stages`
  ADD COLUMN `last_contact_at` datetime DEFAULT NULL AFTER `last_point_at`;
