# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A TYPO3 extension (`schnitzler/database-charset-repair`, key `database_charset_repair`) providing one CLI command,
`schnitzler:database-charset-repair:utf8mb4`. It inspects the raw bytes of every charset-bearing column, decides
per column whether the data is fake latin1, genuine cp1252, or double encoded, repairs rows in SQL, then
re-declares the schema as utf8mb4. TYPO3 13.4 / 14.3, PHP 8.2–8.5, MySQL 8.0.17+ / MariaDB 10.4+ only.

README.md is the user-facing reference (options, plan labels, repair steps, known non-goals).
Documentation/Runbook.md is the background: failure modes, connection charset pitfalls, dump/import verification,
the MySQL-vs-PHP `CONVERT` discrepancies. Read it before changing any SQL predicate.

## Commands

Static checks run locally through composer (needs `vendor/`):

```bash
composer cs            # php-cs-fixer dry run
composer cs:fix
composer phplint
composer phpstan       # level 8 + strict rules, paths listed explicitly in phpstan.neon
composer phpunit:unit  # Domain/ and the DDL builder, plain PHPUnit, no database
```

Functional tests need a real MySQL/MariaDB. SQLite is not an option, the whole extension is engine-specific SQL.
Containerised, the way CI does it (docker or podman on the host, nothing else):

```bash
Build/Scripts/runTests.sh -s composerInstall
Build/Scripts/runTests.sh                              # functional, MariaDB 10.4, PHP 8.2
Build/Scripts/runTests.sh -d mysql -i 8.4 -p 8.5
Build/Scripts/runTests.sh -e "--filter fixRepairsAllThreeFailureModes"       # single test
Build/Scripts/runTests.sh -k -e "--filter fixRepairsAllThreeFailureModes"    # keep the database up between runs
Build/Scripts/runTests.sh -s unit | phpstan | cgl -n | lint | clean          # clean also stops kept databases
Build/Scripts/runTests.sh -h
```

`phpunit.functional.xml` defaults the `typo3Database*` env vars to a local engine (127.0.0.1, root/root), so
`composer phpunit:functional` works against a MariaDB or MySQL installed on the host; real environment variables
override them, which is how runTests.sh and CI point at their own database.

CI (`.github/workflows/ci.yml`) runs lint/phpstan/cs/unit on PHP 8.2 lowest-deps and PHP 8.5 highest-deps only,
and the functional suite on those two against MariaDB 10.4, 10.6, 10.11, 11.4, 11.8 and MySQL 8.0, 8.4, 9. That
is a CI budget decision only: PHP minors are not expected to differ, engines are. Locally runTests.sh still takes
every PHP version with `-p`. Any SQL change must hold on every engine (see below).

## Architecture

Layered by what a test needs to be meaningful, in the Extbase-familiar folder names:

| Layer | Needs | Classes |
|---|---|---|
| `Domain/` | nothing but PHP | `Model/ColumnStats` (counts), `Model/ColumnPlan` (the per-column decision, `decide()`) |
| `Infrastructure/Database/` | a real MySQL/MariaDB | `TableAnalyzer` (SELECTs), `TableRepairer` (DDL + UPDATEs), `Utility/ByteSql` (static SQL fragments, no DI) |
| `Application/Command/` | Symfony console | `Utf8mb4Command`, glue only: options in, tables and exit code out |

Everything is autowired by `Configuration/Services.php`; the services are stateless and take the
`Connection` per call. No row is ever fetched into PHP for repair.

**Byte predicates** (`ByteSql::bytesOf`, `isValidUtf8`, `containsNonAscii`, `withOneLayerRemoved`, `isDoubleEncoded`,
`isPhpSerialized`) return SQL expression strings over `CONVERT(col USING binary)`. They take already quoted
identifiers. They are composed into one aggregate `SELECT` per table (`TableAnalyzer::collectStats`), into the
`WHERE` of the row-repair `UPDATE`s (`ByteSql::transcodeUpdate`, `undoubleUpdate`), and into the review-sample and
CSV-report query (`TableAnalyzer::doubleEncodedRows`). Change a predicate in one place and every consumer follows.

**Flow in `execute()`:**
1. Refuse non-MySQL platforms. Introspect tables (skip `zzz*`), `TableAnalyzer::columnsInScope` keeps columns with a
   charset and skips `ascii`.
2. `TableAnalyzer::collectStats` → per-column `ColumnStats` (rows, nonascii, invalid, doubled, doubled+serialized).
3. `ColumnPlan::decide` per column: `ok`, `redeclare [+transcode] [+undouble] [(review)]`, or `manual`.
   `manual` sets a non-zero exit code. `undouble` requires `doubled/nonascii >= --threshold`. Fixed-width charsets
   (`utf16`, `utf16le`, `utf32`, `ucs2`) are always `manual`: NUL-padded ASCII is valid UTF-8, the bytes cannot say.
4. Without `--fix`: print `TableRepairer::dryRunStatements`. With `--fix`: `TableRepairer::repair` per table, then
   `ALTER DATABASE`.

**`TableRepairer::repair` sequence** (per table, one statement per step, all affected columns at once):
widen to `VARBINARY`/`BLOB` at 4× length → row `UPDATE`s inside `runWithoutStrictMode` → re-declare utf8mb4 with the
original types. On any throwable, execute the prepared rollback DDL. The rollback is a real schema rollback only
because the DDL never transcodes bytes; keep it that way. `buildAlterStatements` is pure over the platform and
unit tested.

## Constraints that shape the SQL

- **No REGEXP on binary strings**: MySQL 8.0.22+ refuses it. Non-ASCII detection uses `OCTET_LENGTH` vs
  `CHAR_LENGTH` instead.
- **NULL-safe compare (`<=>`) in `valid()`**: MySQL yields `NULL` for `CONVERT()` of invalid bytes, MariaDB
  yields `?`. A plain `=` silently drops "invalid" from `SUM()`.
- **Undoubling uses MySQL's `latin1` (really cp1252)**, not PHP `iconv`: it maps all 256 bytes reversibly. The
  `+transcode` row repair reads from the column's *declared* charset, so any truthful single-byte declaration
  works; only double-encoding detection is cp1252-specific (see README "What it can and cannot handle").
- **Strict mode is stripped only around the `UPDATE`s** and restored in `finally`; lossy `CONVERT()` in an
  `UPDATE` is an error in strict mode even in the `WHERE` clause.
- **Serialized values (`a:`, `O:`, `s:` prefix) are never undoubled**; changing byte lengths breaks `s:N:`.
- `utf8` and `utf8mb3` are treated as one family (`UTF8_FAMILY`).
- Undoubling loops at most `MAX_UNDOUBLE_PASSES` (3) times, one layer per pass.

## Tests

Every functional test loads its own fixture, a SQL file next to the test class under `Fixtures/<Class>/<method>.sql`,
holding exactly the rows that test asserts on. Rows are inserted as `UNHEX(...)` bytes and assertions compare `HEX()`
and `information_schema`, never strings. `Tests/Functional/FixtureTestCase.php` only provides `loadFixture`, `hexOf`
and `collationsOf`. Do not share fixture data between tests: precise data beats DRY here.

- `Tests/Unit/Domain/Model/ColumnPlanTest.php`: every plan label branch, no database.
- `Tests/Unit/Infrastructure/Database/TableRepairerTest.php`: the exact DDL strings from `buildAlterStatements`.
- `Tests/Functional/Infrastructure/Database/Utility/ByteSqlTest.php`: a per-row truth table of the predicates and
  the one-layer-removed bytes. This is where MySQL-vs-MariaDB drift shows up first.
- `Tests/Functional/Infrastructure/Database/TableAnalyzerTest.php`: column scoping, per-column counts including a
  NULL row, `doubleEncodedRows` with and without uid.
- `Tests/Functional/Infrastructure/Database/TableRepairerTest.php`: dry-run statements, a full repair, and the
  rollback path (a UNIQUE key in the fixture makes the transcode `UPDATE` collide).
- `Tests/Functional/Application/Command/Utf8mb4CommandTest.php`: end-to-end smoke test through `CommandTester`.

## Conventions

- File header comment is enforced by php-cs-fixer (`.php-cs-fixer.dist.php`); copy it from an existing file.
- Deliberate shortcuts are marked with `// ponytail:` comments naming the ceiling; keep that habit for new ones.
- phpstan lists paths explicitly because functional test instances under `public/` symlink the extension back
  into itself; do not switch it to analysing the project root.
