CREATE TABLE IF NOT EXISTS /*_*/ratepage_vote (
  `rv_page_id` int(10) unsigned NOT NULL,
  `rv_user` varbinary(255) NOT NULL,
  `rv_ip` varbinary(255) default NULL,
  `rv_answer` int(3) NOT NULL,
  `rv_date` datetime NOT NULL,
  `rv_contest` varbinary(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (`rv_page_id`, `rv_contest`, `rv_user`)
) /*$wgDBTableOptions*/;
