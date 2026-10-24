CREATE TABLE global_account_closures (
  gac_user_id INT NOT NULL,
  gac_original_name TEXT NOT NULL,
  gac_closed_name TEXT NOT NULL,
  gac_closed_by TEXT NOT NULL,
  gac_timestamp TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(gac_user_id)
);

CREATE UNIQUE INDEX gac_original_name ON global_account_closures (gac_original_name);

CREATE UNIQUE INDEX gac_closed_name ON global_account_closures (gac_closed_name);