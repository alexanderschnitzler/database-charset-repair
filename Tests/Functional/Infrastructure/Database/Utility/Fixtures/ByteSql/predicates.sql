-- One row per byte pattern the predicates must tell apart.
DROP TABLE IF EXISTS tx_databasecharsetrepair_predicates;

CREATE TABLE tx_databasecharsetrepair_predicates (
  uid INT UNSIGNED NOT NULL,
  value VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_predicates (uid, value) VALUES
  (1, CONVERT(UNHEX('4173636969') USING latin1)),             -- "Ascii"
  (2, CONVERT(UNHEX('C3A4') USING latin1)),                   -- UTF-8 "ä"
  (3, CONVERT(UNHEX('E4') USING latin1)),                     -- cp1252 "ä"
  (4, CONVERT(UNHEX('C383C2A4') USING latin1)),               -- double encoded "ä"
  (5, CONVERT(UNHEX('C383C692C382C2A4') USING latin1)),       -- triple encoded "ä"
  (6, CONVERT(UNHEX('53C3A36F') USING latin1)),               -- "São": valid, one layer down is 53 E3 6F, not UTF-8
  (7, CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1)); -- serialized s:4:"Ã¤"; with a double encoded payload
