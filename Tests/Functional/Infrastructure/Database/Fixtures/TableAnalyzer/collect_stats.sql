-- Two columns with deliberately different byte patterns per row, so the counts differ per column,
-- plus a NULL that must count as a row and nowhere else.
DROP TABLE IF EXISTS tx_databasecharsetrepair_stats;

CREATE TABLE tx_databasecharsetrepair_stats (
  uid INT UNSIGNED NOT NULL,
  a VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  b TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_stats (uid, a, b) VALUES
  (1, CONVERT(UNHEX('4173636969') USING latin1),             CONVERT(UNHEX('4173636969') USING latin1)), -- ASCII            | ASCII
  (2, CONVERT(UNHEX('C3A4') USING latin1),                   CONVERT(UNHEX('E4') USING latin1)),         -- UTF-8 "ä"        | cp1252 "ä"
  (3, CONVERT(UNHEX('E4') USING latin1),                     CONVERT(UNHEX('C383C2A4') USING latin1)),   -- cp1252 "ä"       | double encoded
  (4, CONVERT(UNHEX('C383C2A4') USING latin1),               NULL),                                      -- double encoded   | NULL
  (5, CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1), CONVERT(UNHEX('53C3A36F') USING latin1));   -- serialized, dbl. | "São"
