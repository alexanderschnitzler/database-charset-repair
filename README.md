# schnitzler/database-charset-repair

> Column-level charset repair for TYPO3 databases: fake latin1, genuine cp1252 and double-encoded content, fixed in SQL, then moved to utf8mb4.

`ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4` is the usual answer to a legacy database, and it is exactly how `ä` becomes `Ã¤`. It transcodes every byte as if it were what the column declares, and legacy databases are full of columns that lie: UTF-8 bytes stored under a `latin1` declaration, genuine cp1252 bytes next to them in the same column, and rows that have already been mangled once. This extension looks at the bytes of every column, decides per column what actually happened, repairs the rows that need it, and only then re-declares the schema.

---

## Usage

```bash
# Analyze. Read-only, prints what it found and the SQL it would run.
php vendor/bin/typo3 schnitzler:database-charset-repair:utf8mb4

# Apply.
php vendor/bin/typo3 schnitzler:database-charset-repair:utf8mb4 --fix
```

Run it on a scratch copy of the database first, imported byte-faithfully from a dump. [Documentation/Runbook.md](Documentation/Runbook.md) explains how to verify that the import did not add an encoding layer of its own, which is the most common way to get a database that is "broken" only in the copy.

| Option | Default | Meaning |
|---|---|---|
| `--fix` | off | Execute. Without it the command only analyzes and prints the statements. |
| `--collation` | `utf8mb4_unicode_ci` | Target collation. The charset is always utf8mb4. |
| `--threshold` | `0.9` | Share of a column's non-ASCII rows that must carry the double-encoding signature before the column is undoubled. Below it the column is reported for review with sample rows, and left alone. |
| `--report` | none | CSV path. Every double-encoded row of every column, threshold or not, as `table,column,uid,before,after`. Written during analysis, so it can be reviewed before `--fix`. |
| `--assume` | none | Charset to read invalid bytes in `utf8`/`utf8mb3`/`utf8mb4` columns as (`latin1`, `latin2`, `cp1251`, …). Without it such columns are refused as `manual`. A guess the bytes cannot confirm, so the command prints sample rows; see [Documentation/LegacyBytesInUtf8Columns.md](Documentation/LegacyBytesInUtf8Columns.md). |
| `--table` | all | Restrict to one table. Repeatable. |
| `--column` | all | Restrict to one column, as `table.column`. Repeatable, implies `--table` for that table. Fails when the column is not in scope. |

The exit code is non-zero when a column needs manual attention or a repair failed.

---

## What it finds

Every column with a character set is inspected on its raw bytes (`CONVERT(col USING binary)`). Per column the command counts rows that are non-ASCII, rows that are not valid UTF-8, and rows that carry the double-encoding signature. From that it derives one plan per column:

| Plan | Meaning |
|---|---|
| `ok` | Already utf8mb4 with the target collation, nothing wrong with the bytes. |
| `redeclare` | Bytes are fine, only the declaration is wrong (fake latin1, utf8mb3, wrong collation). Re-declared without transcoding. |
| `redeclare +transcode` | Some rows are genuine legacy bytes in the declared charset. Those rows, and only those, are transcoded from that charset, whichever it is. Rows that already hold valid UTF-8 are untouched, so a column with mixed history is handled row by row. |
| `redeclare +transcode (assumed latin1)` | Same row repair in a column declared `utf8`/`utf8mb3`/`utf8mb4`, reading the invalid rows as the charset `--assume` names, since the declaration cannot name it. Five sample rows are printed, bytes as hex next to the text the assumption makes of them. |
| `redeclare +undouble` | The column is double encoded. One layer is removed per pass, at most three passes, with the number of affected rows printed per pass. |
| `… (review)` | Double-encoded rows exist but below the threshold. Five sample rows are printed with before and after; nothing is undoubled. Lower `--threshold`, ideally together with `--table`, when the samples say it is real. |
| `manual` | Invalid bytes in a column that is already declared utf8 (unless `--assume` names their charset), non-ASCII content in an ENUM/SET, or a column declared in a fixed-width charset (`utf16`, `utf16le`, `utf32`, `ucs2`). The command does not guess here. |

Columns declared `ascii` are skipped: that is a deliberate declaration (core's `sys_refindex.hash`, for instance), not a legacy accident. `BINARY`, `VARBINARY` and `BLOB` columns have no charset and are never in scope.

## What it can and cannot handle

The decision is made from the bytes, and the bytes only answer one structural question reliably: is this valid UTF-8 or not. Everything else follows from the declared charset or from a cp1252-shaped assumption. Concretely:

| Situation | Handled | How |
|---|---|---|
| UTF-8 bytes under any single-byte declaration (`latin1`, `latin2`, `cp1251`, `greek`, `hebrew`, `tis620`, …) | yes | `redeclare`: the bytes are valid UTF-8, the declaration is changed without transcoding |
| Genuine bytes in a truthful single-byte declaration | yes | `+transcode`: rows that are not valid UTF-8 are transcoded from the *declared* charset, whatever it is |
| Genuine bytes in a truthful multi-byte legacy declaration (`gbk`, `big5`, `sjis`, `euckr`, …) | best effort | Same path. A short string whose byte pairs happen to form valid UTF-8 is mistaken for already-UTF-8 and left as is. Check the review samples and the report for such columns |
| `utf8` / `utf8mb3` to utf8mb4 | yes | `redeclare`, mb3 is a subset |
| Double encoding through a `latin1` (cp1252) connection, the MySQL default for years | yes | `+undouble`, one layer per pass, up to three |
| Double encoding through any other connection charset (`cp1251`, `latin2`, …) | no | The signature differs and the round-trip test rejects it. Such rows are valid UTF-8 and come out as `redeclare`, still mangled |
| Mixed history within one column (some rows UTF-8, some cp1252, some doubled) | yes | Every row is classified on its own bytes; only matching rows are touched by each `UPDATE` |
| A single-byte declaration that lies about *which* single-byte charset (`latin1` holding `latin2` bytes) | no | Undecidable from bytes, every byte is valid in every single-byte charset. Transcoded as declared; wrong glyphs, but reversible |
| Fixed-width declarations (`utf16`, `utf16le`, `utf32`, `ucs2`) | refused | ASCII in them is NUL-padded and NUL is valid UTF-8, so the content looks like fake latin1. Reported as `manual`, convert with `ALTER TABLE … MODIFY … CHARACTER SET utf8mb4` by hand, which is correct there because the declaration is truthful |
| Invalid bytes in a column already declared utf8/utf8mb3/utf8mb4 | with `--assume` | The declaration cannot name the charset the bytes are in, so the command does not guess. Reported as `manual` until `--assume latin1` (or whichever single-byte charset the site had) supplies it; then the same per-row `+transcode`. [Documentation/LegacyBytesInUtf8Columns.md](Documentation/LegacyBytesInUtf8Columns.md) walks through the bytes |
| Non-ASCII content in ENUM/SET | refused | The declaration itself would need new values. Reported as `manual` |

In short: any truthful declaration is transcoded correctly, the fake-latin1 pattern is caught under any single-byte declaration, and double encoding is caught only when it went through cp1252, which is the common case in TYPO3 installations but not the only possible one.

## How it repairs

Per table, with everything in the table's work set in one statement each:

1. **Widen to binary.** Every affected column becomes `VARBINARY` or `BLOB` at four times its length, bytes untouched. This is what makes the row repairs safe from truncation: a legacy charset to UTF-8 grows to at most three bytes per character, undoubling shrinks. `VARCHAR(n)` utf8mb4 reserves the same four bytes per character, so the intermediate is never larger than the target.
2. **Repair rows.** Strict SQL mode is switched off for these statements only. In strict mode a lossy `CONVERT()` inside an `UPDATE` is an error, even in the `WHERE` clause, while in a `SELECT` it is a warning. On a binary column with no charset to validate against and enough room, non-strict mode cannot lose anything.
3. **Re-declare as utf8mb4** with the original types and lengths, plus the table default. `TEXT` stays `TEXT` instead of being promoted to `MEDIUMTEXT` the way `CONVERT TO` does, so the result still matches what `ext_tables.sql` declares.
4. On any error the original declarations are restored. Bytes were never transcoded by the DDL, so that is a real rollback of the schema. Row repairs that already ran leave valid UTF-8 in a legacy-declared column, which is the fake-latin1 case, and a rerun picks it up cleanly.

Finally `ALTER DATABASE` sets the default for future tables. It is skipped when the run was scoped with `--table` or `--column`, since the rest of the schema is still legacy then. A second run finds nothing to do.

Everything is SQL. No row is fetched into PHP, no primary key is required, and MySQL's own latin1 table is used for the cp1252 transcoding, which maps all 256 bytes reversibly where PHP's `iconv` silently drops five of them.

---

## What it does not do

- **PHP-serialized values** are excluded from undoubling and counted separately, because changing byte lengths inside `s:N:"…"` breaks the value. In TYPO3 these usually live in `BLOB` columns that have no charset and are never touched anyway.
- **No UNIQUE collision check** before an accent-insensitive collation. `utf8mb4_unicode_ci` treats `ö` and `o` as equal, `latin1_swedish_ci` did not. If two rows collide, the re-declare fails with `Duplicate entry`, the rollback keeps the table consistent, and the message names the key.
- **No NFC normalization.** Content pasted from macOS can be NFD (`a` plus combining diaeresis). Identical-looking, different bytes, a separate problem.
- **No connection configuration.** The command warns when `DB.Connections.Default.charset` is not `utf8mb4`, but does not edit `config/system/settings.php`. Align `charset` and `tableoptions` there after the migration, or the DatabaseAnalyzer keeps proposing conversions and 4-byte characters break on write.
- **No filesystem.** `sys_file.identifier` and `sys_file.name` may show up in the review bucket with names like `EntzÃ¼nden.jpg`. Check the storage before touching them: if the file on disk carries the same mangled name, the row is correct and undoubling it orphans the record. Renaming is a FAL operation, not a charset repair.

## Requirements

MySQL 8.0.17+ or MariaDB 10.4+, which is what TYPO3 13 requires anyway. The command refuses other platforms. It relies on nothing that differs between the two: no regular expressions on binary strings (MySQL 8.0.22 removed them), and both `utf8` and `utf8mb3` are recognized as the same family.

---

## Development

The per-column decision and the DDL builder are plain PHP and covered by a unit suite that needs no database (`composer phpunit:unit`). The functional tests need a real MariaDB or MySQL, because the whole point is engine-specific SQL, and the two engines do differ: MySQL yields `NULL` where MariaDB yields `?` for a `CONVERT()` of invalid bytes, and MySQL's regex engine overflows on long strings where MariaDB's does not. So that suite has to run on both.

`Build/Scripts/runTests.sh` starts a throw-away database container and a TYPO3 core-testing PHP container per run, the way the TYPO3 core does it. It needs docker or podman on the host and nothing else.

```bash
Build/Scripts/runTests.sh -s composerInstall
Build/Scripts/runTests.sh                          # functional, MariaDB 10.4, PHP 8.2: the floor
Build/Scripts/runTests.sh -d mysql -i 8.4 -p 8.5
Build/Scripts/runTests.sh -e "--filter repair"     # extra phpunit options
Build/Scripts/runTests.sh -k -e "--filter repair"  # keep the database up between runs: 2 s instead of 15, data left for inspection
Build/Scripts/runTests.sh -s unit                  # no database needed
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s cgl -n                # php-cs-fixer dry run
Build/Scripts/runTests.sh -h                       # all options, engine and PHP versions

# The whole engine matrix
for db in "mariadb 10.4" "mariadb 10.6" "mariadb 10.11" "mariadb 11.4" "mariadb 11.8" "mysql 8.0" "mysql 8.4" "mysql 9"; do
    Build/Scripts/runTests.sh -d ${db% *} -i ${db#* } || break
done
```

`phpunit.functional.xml` defaults to a MariaDB or MySQL on `127.0.0.1` with `root`/`root`, so `composer phpunit:functional` works against a locally installed engine too, and real environment variables override those defaults. CI runs everything on PHP 8.2 with lowest and PHP 8.5 with highest dependencies, the code is not expected to differ between PHP minors; the functional suite additionally fans out over MariaDB 10.4, 10.6, 10.11, 11.4 and 11.8 and MySQL 8.0, 8.4 and 9, because the engine is where behaviour differs.

---

## Provenance and license

This extension was written with Claude Fable 5.1 (Anthropic) in pair-programming sessions, starting from a problem description. The Runbook came out of an earlier session of the same kind; the code, the tests, the docblocks and this README out of later ones. The problem framing, the design decisions, the questioning of every rule and the sign-off are human work; the text was produced by the model and then read, challenged and corrected. Treat it as you would any contribution from a colleague you do not know: the test suite is the evidence, not the author.

The code is licensed under [GPL-2.0-or-later](LICENSE.txt), as TYPO3 extensions are. Whether model-generated code carries copyright at all is unsettled in most jurisdictions; where it does not, the license grant is broader than it needs to be, never narrower. Nothing in this repository was taken from another project, and no code was copied from a package's source: the Runbook names the packages that were looked at and why they were not used.
