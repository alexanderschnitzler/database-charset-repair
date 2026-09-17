-- Rows for the --assume samples: invalid UTF-8 listed as hex plus the text it becomes when read
-- as the assumed charset, valid rows not listed. A binary column, the predicates read bytes only.
DROP TABLE IF EXISTS tx_databasecharsetrepair_transcoded;

CREATE TABLE tx_databasecharsetrepair_transcoded (
  uid INT UNSIGNED NOT NULL AUTO_INCREMENT,
  value BLOB,
  PRIMARY KEY (uid)
) ENGINE=InnoDB;

INSERT INTO tx_databasecharsetrepair_transcoded (uid, value) VALUES
  (1, UNHEX('E4')),           -- latin1 "ä", cp1251 "д"
  (2, UNHEX('C3A4')),         -- UTF-8 "ä": valid, not listed
  (3, UNHEX('4DFC6C6C6572')); -- latin1 "Müller"
