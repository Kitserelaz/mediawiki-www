CREATE TABLE /*_*/cw_custom_domain_cloudflare (
  cdf_host VARBINARY(253) NOT NULL PRIMARY KEY,
  cdf_request BINARY(48) NOT NULL,
  cdf_id VARBINARY(36) NULL,
  cdf_created BIGINT UNSIGNED NOT NULL
) /*$wgDBTableOptions*/;
