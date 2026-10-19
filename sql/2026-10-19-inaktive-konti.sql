-- Sletning af inaktive konti og lavere lagerkvote.
--
-- last_active_at: sidste dag brugeren var logget ind på sitet eller brugte
--   appen (opdateres højst én gang i døgnet i current_user()).
-- inactivity_warning_level: 0 = ingen advarsel, 1 = 30-dages-advarsel
--   sendt, 2 = 7-dages-advarsel sendt. Nulstilles ved aktivitet.
-- inactivity_warned_at: hvornår seneste advarsel blev sendt, så der altid
--   går mindst 30 og 7 dage, også hvis cron-jobbet har været nede.
ALTER TABLE users
  ADD COLUMN last_active_at datetime DEFAULT NULL AFTER updated_at,
  ADD COLUMN inactivity_warning_level tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER last_active_at,
  ADD COLUMN inactivity_warned_at datetime DEFAULT NULL AFTER inactivity_warning_level,
  ADD KEY idx_users_last_active (status, last_active_at),
  ALTER COLUMN storage_quota_bytes SET DEFAULT 262144000;

-- Vi kender ikke eksisterende brugeres sidste login, så alle får fuld
-- frist fra i dag.
UPDATE users SET last_active_at = NOW() WHERE status = 'active';

-- 250 MB for alle almindelige brugere, der har standardkvoten på 500 MB.
-- Brugere, der allerede bruger mere, beholder deres data, men kan ikke
-- uploade mere, før de har slettet noget.
UPDATE users SET storage_quota_bytes = 262144000
WHERE is_admin = 0 AND storage_quota_bytes = 524288000;
