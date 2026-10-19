-- 2026-10-19: Live-tracking (branch live-skema)
-- Køres på dev (tracks_dev) og derefter på produktion (tracks).
--
-- live_events: livesendingen med en fast URL (public_id), oprettes på forhånd.
-- live_stages: etaperne. En færdig etape bliver et almindeligt spor (route_id);
--              ved anden etape oprettes en gruppe (live_events.group_id).
-- live_points: rå punkter fra appen, mens en etape er i gang.

CREATE TABLE `live_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(8) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `activity_type_id` tinyint(3) unsigned NOT NULL,
  `status` enum('planlagt','live','pause','afsluttet') NOT NULL DEFAULT 'planlagt',
  `visibility` enum('private','public') NOT NULL DEFAULT 'private',
  `share_token` varchar(64) DEFAULT NULL,
  `delay_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  `planned_start_at` datetime DEFAULT NULL,
  `group_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_live_events_public_id` (`public_id`),
  UNIQUE KEY `uq_live_events_share_token` (`share_token`),
  KEY `idx_live_events_user` (`user_id`, `status`),
  KEY `idx_live_events_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `live_stages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint(20) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  `status` enum('planlagt','live','afsluttet') NOT NULL DEFAULT 'planlagt',
  `planned_route_id` bigint(20) unsigned DEFAULT NULL,
  `client_upload_id` char(36) DEFAULT NULL,
  `route_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `last_point_at` datetime(3) DEFAULT NULL,
  `point_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_live_stages_position` (`event_id`, `position`),
  UNIQUE KEY `uq_live_stages_client_upload` (`event_id`, `client_upload_id`),
  KEY `idx_live_stages_route` (`route_id`),
  KEY `idx_live_stages_planned` (`planned_route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `live_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stage_id` bigint(20) unsigned NOT NULL,
  `recorded_at` datetime(3) NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lon` decimal(9,6) NOT NULL,
  `ele` decimal(7,1) DEFAULT NULL,
  `accuracy_m` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_live_points_stage_time` (`stage_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
