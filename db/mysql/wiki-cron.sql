-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db/wiki-cron.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/wiki_cron (
  wc_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  wc_name VARBINARY(255) NOT NULL,
  wc_interval VARBINARY(255) NOT NULL,
  wc_enabled TINYINT UNSIGNED NOT NULL,
  wc_last_run BINARY(14) DEFAULT NULL,
  wc_steps LONGTEXT DEFAULT NULL,
  wc_timeout INT NOT NULL,
  wc_manual_interval VARBINARY(255) DEFAULT NULL,
  PRIMARY KEY(wc_id)
) /*$wgDBTableOptions*/;


CREATE TABLE /*_*/wiki_cron_history (
  wch_cron VARBINARY(255) NOT NULL,
  wch_time BINARY(14) NOT NULL,
  wch_pid VARBINARY(128) NOT NULL
) /*$wgDBTableOptions*/;
