-- 2026-11-03: Waypoints under livesending (branch live-waypoints-skema)
-- Køres på dev (tracks_dev) og derefter på produktion (tracks).
--
-- live_waypoints: waypoints, appen sender, mens en etape er i gang. De vises
-- på livesiden (med samme forsinkelse som punkterne), indtil etapens færdige
-- spor vises; derefter bruges sporets egne <wpt>.
-- Et waypoint kendes på sin tid (recorded_at), så appen kan sende det igen,
-- fx når billederne er uploadet; så opdateres rækken.
-- images: JSON-liste med billedstier (ejerens egne billeder), fx
--         ["billeder/25/<32 hex-tegn>.jpg"].

CREATE TABLE `live_waypoints` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stage_id` bigint(20) unsigned NOT NULL,
  `recorded_at` datetime(3) NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lon` decimal(9,6) NOT NULL,
  `ele` decimal(7,1) DEFAULT NULL,
  `name` varchar(200) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `images` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_live_waypoints_stage_time` (`stage_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
