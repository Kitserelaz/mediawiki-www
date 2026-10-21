CREATE TABLE IF NOT EXISTS /*_*/ratepage_contest (
  `rpc_id` varbinary(255) NOT NULL PRIMARY KEY,
  `rpc_description` blob NOT NULL,
  `rpc_enabled` tinyint(1) NOT NULL,
  `rpc_allowed_to_vote` blob NOT NULL,
  `rpc_allowed_to_see` blob NOT NULL,
  `rpc_see_before_vote` tinyint(1) NOT NULL
) /*$wgDBTableOptions*/;