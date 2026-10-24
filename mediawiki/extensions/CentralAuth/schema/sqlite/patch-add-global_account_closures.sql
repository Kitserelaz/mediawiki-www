CREATE TABLE /*_*/global_account_closures (
  gac_user_id INTEGER NOT NULL,
  gac_original_name BLOB NOT NULL,
  gac_closed_name BLOB NOT NULL,
  gac_closed_by BLOB NOT NULL,
  gac_timestamp BLOB NOT NULL,
  PRIMARY KEY(gac_user_id)
);

CREATE UNIQUE INDEX gac_original_name ON /*_*/global_account_closures (gac_original_name);

CREATE UNIQUE INDEX gac_closed_name ON /*_*/global_account_closures (gac_closed_name);