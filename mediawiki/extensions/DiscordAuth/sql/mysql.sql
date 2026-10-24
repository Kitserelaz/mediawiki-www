CREATE TABLE /*_*/discordauth_link (
  dal_discord_id VARBINARY(20) NOT NULL,
  dal_global_user INT UNSIGNED NOT NULL,
  dal_created BINARY(14) NOT NULL,
  PRIMARY KEY (dal_discord_id),
  UNIQUE KEY dal_global_user (dal_global_user)
) /*$wgDBTableOptions*/;
