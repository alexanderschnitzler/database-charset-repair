-- Every failure mode in a VARCHAR and a TEXT column, so both the VARBINARY and the BLOB detour are exercised.
DROP TABLE IF EXISTS tx_databasecharsetrepair_repair;

CREATE TABLE tx_databasecharsetrepair_repair (
  uid INT UNSIGNED NOT NULL,
  a_varchar VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  a_text TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_repair (uid, a_varchar, a_text) VALUES
  (1, CONVERT(UNHEX('4173636969546578742030') USING latin1), CONVERT(UNHEX('4173636969546578742030') USING latin1)), -- ASCII
  (2, CONVERT(UNHEX('C3A4') USING latin1), CONVERT(UNHEX('C3A4') USING latin1)),                                     -- fake latin1: UTF-8 "ä"
  (3, CONVERT(UNHEX('E4') USING latin1), CONVERT(UNHEX('E4') USING latin1)),                                         -- genuine cp1252 "ä"
  (4, CONVERT(UNHEX('C383C2A4') USING latin1), CONVERT(UNHEX('C383C2A4') USING latin1)),                             -- double encoded "ä"
  (5, CONVERT(UNHEX('C383C692C382C2A4') USING latin1), CONVERT(UNHEX('C383C692C382C2A4') USING latin1)),             -- triple encoded "ä"
  (6, CONVERT(UNHEX('53C3A36F') USING latin1), CONVERT(UNHEX('53C3A36F') USING latin1)),                             -- "São": untouched
  (7, CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1), CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1)); -- serialized: untouched
