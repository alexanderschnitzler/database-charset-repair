-- One cp1252 row, two undoublable rows and one serialized row that the undouble count must exclude.
DROP TABLE IF EXISTS tx_databasecharsetrepair_dryrun;

CREATE TABLE tx_databasecharsetrepair_dryrun (
  uid INT UNSIGNED NOT NULL,
  a_varchar VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  a_text TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_dryrun (uid, a_varchar, a_text) VALUES
  (1, CONVERT(UNHEX('E4') USING latin1), CONVERT(UNHEX('E4') USING latin1)),                                         -- cp1252 "ä"
  (2, CONVERT(UNHEX('C383C2A4') USING latin1), CONVERT(UNHEX('C383C2A4') USING latin1)),                             -- double encoded
  (3, CONVERT(UNHEX('C383C692C382C2A4') USING latin1), CONVERT(UNHEX('C383C692C382C2A4') USING latin1)),             -- triple encoded
  (4, CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1), CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1)); -- serialized
