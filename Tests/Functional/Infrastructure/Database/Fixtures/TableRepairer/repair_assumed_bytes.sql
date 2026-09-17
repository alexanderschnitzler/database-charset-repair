-- Phase 2 of 2: the bytes a utf8 column carries after a latin1 history whose label was switched
-- without transcoding, planted through BLOB because a utf8 declaration refuses them today.
ALTER TABLE tx_databasecharsetrepair_assumed MODIFY a_text BLOB;

INSERT INTO tx_databasecharsetrepair_assumed (uid, a_text) VALUES
  (1, UNHEX('E4')),                     -- real latin1 "ä"
  (2, UNHEX('C3A4')),                   -- UTF-8 "ä" that slipped in years ago (fake latin1 back then)
  (3, UNHEX('4DFC6C6C6572')),           -- real latin1 "Müller"
  (4, UNHEX('4D69746172626569746572')); -- ASCII "Mitarbeiter"
