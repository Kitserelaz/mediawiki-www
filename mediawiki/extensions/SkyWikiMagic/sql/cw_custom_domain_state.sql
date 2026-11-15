CREATE TABLE /*_*/cw_custom_domain_state (
  cd_wiki VARCHAR(64) NOT NULL PRIMARY KEY,
  cd_candidate VARBINARY(253) NULL,
  cd_token BINARY(48) NULL,
  cd_requester INT UNSIGNED NULL,
  cd_expires BIGINT UNSIGNED NULL,
  cd_due BIGINT UNSIGNED NULL,
  cd_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cd_error VARBINARY(32) NULL,
  cd_cache_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cd_cache_pending TINYINT NOT NULL DEFAULT 0
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/cd_due ON /*_*/cw_custom_domain_state (cd_due);
CREATE INDEX /*i*/cd_expires ON /*_*/cw_custom_domain_state (cd_expires);
CREATE INDEX /*i*/cd_cache_pending ON /*_*/cw_custom_domain_state (cd_cache_pending);
