CREATE TABLE /*_*/global_account_closures (
  gac_user_id INT NOT NULL,
  gac_original_name VARBINARY(255) NOT NULL,
  gac_closed_name VARBINARY(255) NOT NULL,
  gac_closed_by VARBINARY(255) NOT NULL,
  gac_timestamp BINARY(14) NOT NULL,
  UNIQUE INDEX gac_original_name (gac_original_name),
  UNIQUE INDEX gac_closed_name (gac_closed_name),
  PRIMARY KEY(gac_user_id)
) /*$wgDBTableOptions*/;