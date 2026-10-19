-- 2026-10-19: Billeder fra appen (branch api-billeder)
-- Køres på dev (tracks_dev) og derefter på produktion (tracks), FØR git pull.
--
-- client_upload_id: UUID fra appen, så et gentaget upload af samme billede
-- (fx efter en netværksfejl) ikke giver en dublet. NULL for billeder fra sitet.

ALTER TABLE `user_images`
  ADD COLUMN `client_upload_id` char(36) DEFAULT NULL AFTER `size_bytes`,
  ADD UNIQUE KEY `uq_user_images_client_upload` (`user_id`,`client_upload_id`);
