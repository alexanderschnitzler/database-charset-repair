-- TEXT columns with expression defaults (DEFAULT (NULL), DEFAULT ('foo')) that must survive the re-declaration.
DROP TABLE IF EXISTS tx_databasecharsetrepair_textdefaults;

CREATE TABLE tx_databasecharsetrepair_textdefaults (
  uid INT UNSIGNED NOT NULL,
  a_null TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT (NULL),
  a_foo TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT ('foo'),
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_textdefaults (uid, a_null, a_foo) VALUES
  (1, CONVERT(UNHEX('E4') USING latin1), CONVERT(UNHEX('E4') USING latin1)); -- genuine cp1252 "ä"
