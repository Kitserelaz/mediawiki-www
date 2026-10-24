CREATE TABLE /*_*/discordauth_transaction (
  dat_state BINARY(64) NOT NULL,
  dat_binding BINARY(64) NOT NULL,
  dat_expires BIGINT UNSIGNED NOT NULL,
  dat_payload BLOB NOT NULL,
  PRIMARY KEY (dat_state),
  KEY dat_expires (dat_expires)
) /*$wgDBTableOptions*/;
