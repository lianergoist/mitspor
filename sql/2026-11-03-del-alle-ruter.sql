-- Del alle mine ruter: ét link pr. bruger, der giver adgang til alle
-- brugerens ruter, spor og grupper (også private). Se app/user_share.php.
ALTER TABLE users
  ADD COLUMN all_share_token varchar(64) DEFAULT NULL AFTER public_route_notice_ack_at,
  ADD UNIQUE KEY uq_users_all_share_token (all_share_token);
