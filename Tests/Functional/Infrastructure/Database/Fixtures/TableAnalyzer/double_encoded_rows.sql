-- Doubled and non-doubled values with a uid, and the same shape without a uid column.
DROP TABLE IF EXISTS tx_databasecharsetrepair_doubled;
DROP TABLE IF EXISTS tx_databasecharsetrepair_doubled_nouid;

CREATE TABLE tx_databasecharsetrepair_doubled (
  uid INT UNSIGNED NOT NULL,
  value VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_doubled (uid, value) VALUES
  (1, CONVERT(UNHEX('C3A4') USING latin1)),                   -- UTF-8 "ä": not doubled
  (2, CONVERT(UNHEX('C383C2A4') USING latin1)),               -- double encoded "ä"
  (3, CONVERT(UNHEX('C383C692C382C2A4') USING latin1)),       -- triple encoded "ä"
  (4, CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1)), -- serialized s:4:"Ã¤"; listed, never rewritten
  (5, CONVERT(UNHEX('53C3A36F') USING latin1));               -- "São": not doubled

CREATE TABLE tx_databasecharsetrepair_doubled_nouid (
  value VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_doubled_nouid (value) VALUES
  (CONVERT(UNHEX('C383C2A4') USING latin1)), -- double encoded "ä"
  (CONVERT(UNHEX('C3A4') USING latin1));     -- UTF-8 "ä": not doubled
