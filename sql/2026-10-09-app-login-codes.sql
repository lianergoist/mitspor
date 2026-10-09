-- Engangskoder, der forbinder login i appen og login på sitet (2026-10-09).
-- app_til_web: appen (med token) får en kode, som /app-login.php veksler til en session.
-- web_til_app: sitet (med session) laver en kode, som appen veksler til et token.
-- Kun SHA-256 af koden gemmes. Koderne gælder kort tid og kan bruges én gang.

CREATE TABLE `app_login_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `direction` enum('app_til_web','web_til_app') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_login_codes_hash` (`code_hash`),
  KEY `idx_app_login_codes_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
