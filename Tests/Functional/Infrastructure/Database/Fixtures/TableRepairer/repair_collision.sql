-- A UNIQUE key and two rows that become the same bytes once the cp1252 row is transcoded.
DROP TABLE IF EXISTS tx_databasecharsetrepair_collision;

CREATE TABLE tx_databasecharsetrepair_collision (
  uid INT UNSIGNED NOT NULL,
  a_varchar VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  PRIMARY KEY (uid),
  UNIQUE KEY collide (a_varchar)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_collision (uid, a_varchar) VALUES
  (1, CONVERT(UNHEX('C3A4') USING latin1)), -- UTF-8 "ä", already what row 2 will become
  (2, CONVERT(UNHEX('E4') USING latin1));   -- cp1252 "ä"
