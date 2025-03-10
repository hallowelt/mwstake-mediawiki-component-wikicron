-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db/wiki-cron.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/wiki_cron (
  wc_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  wc_name BLOB NOT NULL, wc_interval BLOB NOT NULL,
  wc_enabled SMALLINT UNSIGNED NOT NULL,
  wc_last_run BLOB DEFAULT NULL, wc_steps CLOB DEFAULT NULL,
  wc_timeout INTEGER NOT NULL, wc_manual_interval BLOB DEFAULT NULL
);


CREATE TABLE /*_*/wiki_cron_history (
  wch_cron BLOB NOT NULL, wch_time BLOB NOT NULL,
  wch_pid BLOB NOT NULL
);
