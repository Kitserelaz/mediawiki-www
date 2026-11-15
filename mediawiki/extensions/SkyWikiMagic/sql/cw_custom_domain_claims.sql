CREATE TABLE /*_*/cw_custom_domain_claims (
  cdc_host VARBINARY(253) NOT NULL PRIMARY KEY,
  cdc_wiki VARCHAR(64) NOT NULL,
  cdc_active TINYINT NOT NULL
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/cdc_wiki_slot ON /*_*/cw_custom_domain_claims (cdc_wiki, cdc_active);
