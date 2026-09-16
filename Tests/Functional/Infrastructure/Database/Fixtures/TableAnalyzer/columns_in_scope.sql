-- One column per kind of declaration; no rows, only the schema matters here.
DROP TABLE IF EXISTS tx_databasecharsetrepair_scope;

CREATE TABLE tx_databasecharsetrepair_scope (
  uid INT UNSIGNED NOT NULL AUTO_INCREMENT,
  a_latin1 VARCHAR(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
  a_utf8mb4 TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  a_enum ENUM('a', 'b') CHARACTER SET latin1 NOT NULL DEFAULT 'a',
  a_ascii VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '',
  a_binary VARBINARY(10) NOT NULL DEFAULT '',
  a_int INT NOT NULL DEFAULT 0,
  a_datetime DATETIME DEFAULT NULL,
  PRIMARY KEY (uid)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
