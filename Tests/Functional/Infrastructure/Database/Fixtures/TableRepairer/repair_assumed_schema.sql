-- Phase 1 of 2: the declaration the test introspects, a utf8 column, still empty.
-- No current MySQL or MariaDB lets invalid bytes into a utf8 column (strict mode errors,
-- non-strict substitutes ? or truncates), so the bytes go in after the introspection, through
-- BLOB, in repair_assumed_bytes.sql. Documentation/LegacyBytesInUtf8Columns.md has the measurements.
DROP TABLE IF EXISTS tx_databasecharsetrepair_assumed;

CREATE TABLE tx_databasecharsetrepair_assumed (
  uid INT UNSIGNED NOT NULL AUTO_INCREMENT,
  a_text TEXT CHARACTER SET utf8 COLLATE utf8_general_ci,
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
