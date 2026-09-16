-- Every failure mode side by side, plus columns the command must leave alone or refuse.
DROP TABLE IF EXISTS tx_databasecharsetrepair_command;

CREATE TABLE tx_databasecharsetrepair_command (
  uid INT UNSIGNED NOT NULL AUTO_INCREMENT,
  a_varchar VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  a_text TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  a_utf8mb4 TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  a_enum ENUM('a', 'b', 'c') CHARACTER SET latin1 NOT NULL DEFAULT 'a',
  a_ascii VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '',
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

INSERT INTO tx_databasecharsetrepair_command (uid, a_varchar, a_text, a_utf8mb4, a_enum) VALUES
  (1, CONVERT(UNHEX('4173636969546578742030') USING latin1), CONVERT(UNHEX('4173636969546578742030') USING latin1), 'ok', 'a'), -- ASCII
  (2, CONVERT(UNHEX('C3A4') USING latin1), CONVERT(UNHEX('C3A4') USING latin1), 'ok', 'a'),                                     -- fake latin1: UTF-8 "ä"
  (3, CONVERT(UNHEX('E4') USING latin1), CONVERT(UNHEX('E4') USING latin1), 'ok', 'a'),                                         -- genuine cp1252 "ä"
  (4, CONVERT(UNHEX('C383C2A4') USING latin1), CONVERT(UNHEX('C383C2A4') USING latin1), 'ok', 'a'),                             -- double encoded "ä"
  (5, CONVERT(UNHEX('C383C692C382C2A4') USING latin1), CONVERT(UNHEX('C383C692C382C2A4') USING latin1), 'ok', 'a'),             -- triple encoded "ä"
  (6, CONVERT(UNHEX('53C3A36F') USING latin1), CONVERT(UNHEX('53C3A36F') USING latin1), 'ok', 'a'),                             -- "São": valid, looks doubled but is not
  (7, CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1), CONVERT(UNHEX('733A343A22C383C2A4223B') USING latin1), 'ok', 'a'); -- serialized s:4:"Ã¤";
