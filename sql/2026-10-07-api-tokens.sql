-- 2026-10-07: Token-baseret API til mobilappen (branch api-token)
-- Køres på dev (tracks_dev) og derefter på produktion (tracks).

CREATE TABLE `api_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `device_name` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_tokens_hash` (`token_hash`),
  KEY `idx_api_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE `routes`
  ADD COLUMN `client_upload_id` char(36) DEFAULT NULL AFTER `public_id`,
  ADD UNIQUE KEY `uq_routes_user_client_upload` (`user_id`, `client_upload_id`);
