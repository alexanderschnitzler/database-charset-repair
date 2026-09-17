# MySQL Charset Repair & utf8mb4 Migration — Runbook

Context: PHP / TYPO3, legacy databases with latin1, utf8 (mb3), utf8mb4 and
double-encoded content mixed together.

---

## 1. The three failure modes

Everything reduces to these. Each needs a *different* fix.

| # | Name | What it is | Fix |
|---|------|-----------|-----|
| 1 | **Fake latin1** | Valid UTF-8 bytes in a latin1 column (connection was latin1 → pass-through) | Metadata only, BLOB round-trip |
| 2 | **Real legacy** | Genuine cp1252/latin1 bytes in a latin1 column | `CONVERT TO CHARACTER SET` |
| 3 | **Double encoded** | UTF-8 read as cp1252 and re-encoded. `ä` = `C3 83 C2 A4` instead of `C3 A4` | Per-row `UPDATE`, one layer per pass |
| 2b | **Legacy bytes under a utf8 label** | Case 2 whose column was later declared utf8 without transcoding; the declaration no longer names the charset | Case 2 with `--assume`, see [LegacyBytesInUtf8Columns.md](LegacyBytesInUtf8Columns.md) |

Case 1 and 2 are cheap. Only case 3 is a real data rewrite. Case 2b is case 2 with one
piece of information missing, and a current server cannot even produce it any more; the
linked document has the byte walkthrough and the measurements.

---

## 2. Why it happens: the connection

**The column charset is the only thing that decides what is stored on disk.**
Database and table charsets are just defaults for *new* columns — they never
touch existing bytes.

`SET NAMES x` sets three session variables:

- `character_set_client` — what the server assumes incoming bytes are
- `character_set_connection` — what literals are converted to for comparison
- `character_set_results` — what result rows are encoded in on the way out

On INSERT the server transcodes from `character_set_connection` into the column
charset. **If the two are equal it does nothing at all** — bytes pass through
verbatim, unvalidated. That single fact explains the whole mess:

| you send | SET NAMES | column | outcome |
|---|---|---|---|
| UTF-8 | latin1 | latin1 | pass-through → case 1. Lossless, recoverable |
| UTF-8 | latin1 | utf8mb4 | re-encoded → **case 3, double encoding** |
| UTF-8 | utf8mb4 | utf8mb4 | correct |
| cp1252 | utf8mb4 | utf8mb4 | error 1366 or truncation at the first umlaut |
| cp1252 | latin1 | utf8mb4 | correct transcoding |

Classic history: old app with latin1 connection + latin1 columns (harmless
pass-through) → someone converts columns to utf8mb4 without fixing the
connection → double encoding is born.

`character_set_results` decides what you *see*. Wrong results charset makes
intact data look broken and broken data look fine. **That is why `HEX()` is the
detection tool** — it returns ASCII hex digits and cannot be mangled on the way
out.

### PHP: set it on the driver, not with a query

```php
// PDO
new PDO('mysql:host=…;dbname=…;charset=utf8mb4', $u, $p);

// mysqli
$mysqli->set_charset('utf8mb4');
```

`$pdo->query("SET NAMES utf8mb4")` changes server state but leaves the *client
library* on the old charset, so `mysqli_real_escape_string()` escapes with wrong
byte-width assumptions. Historically an injection vector.

TYPO3 passes `['DB']['Connections']['Default']['charset']` to the driver
correctly, so that one key is enough — but it also feeds the schema analyzer, so
it must match the actual column charsets or `DatabaseAnalyzer` keeps proposing
conversions.

Session check: `SHOW VARIABLES LIKE 'character_set%';` — client, connection,
results and database should all read `utf8mb4`.

---

## 3. Detection

### What is decidable, and what is not

**Deterministic:** valid UTF-8 or not. UTF-8 is self-validating:

```
0xxxxxxx                              ASCII, 1 byte
110xxxxx 10xxxxxx                     2 bytes  (C2–DF, then 80–BF)
1110xxxx 10xxxxxx 10xxxxxx            3 bytes  (E0–EF …)
11110xxx 10xxxxxx 10xxxxxx 10xxxxxx   4 bytes  (F0–F4 …)
```

A lead byte announces its own length; every continuation byte must be
`10xxxxxx`. Most random byte strings fail within a few hundred bytes. That is a
proof of structure, not a guess. Case 2 is settled by it.

**Single-byte encodings have no structure to check.** In latin1 all 256 byte
values are valid, so "is this latin1?" is always yes. Distinguishing latin1 /
cp1252 / ISO-8859-15 is impossible in principle — only statistically guessable.

**Case 3 is genuinely ambiguous.** `Ã¤` is a perfectly valid UTF-8 string.
Nothing in the bytes distinguishes "the author wrote Ã followed by ¤" from
"this is a mangled ä". The information about intent was never in the string.

But you need far less than "expected language". Double encoding produces bigrams
that essentially no orthography generates: `Ã` followed by a C1-range symbol
(`¤ § © ­ ®`), or `Â` followed by punctuation. You reject sequences no writing
system produces, rather than matching one you expect — which is why it works
across languages.

Ambiguity only really survives in **short strings**: names, slugs, single-word
labels. `São` alone is undecidable. A paragraph is not — density decides it.

**What actually collapses the ambiguity is provenance, not analysis.** The
corruption was a single event applied uniformly to a whole table. Decide at
*column* level: if 90 % of non-ASCII rows in `tt_content.bodytext` carry the
signature, the column was mangled. That turns "human in the loop" into a bounded
review list.

### Where the detection lives

The predicates are SQL and sit in `Classes/Infrastructure/Database/Utility/ByteSql.php`:
`isValidUtf8`, `containsNonAscii`, `isDoubleEncoded`, `isPhpSerialized`, and
`withOneLayerRemoved` for the repair. Their behaviour on every byte pattern is
pinned per row in `Tests/Functional/Infrastructure/Database/Utility/ByteSqlTest.php`,
which is the fastest way to see what each one says about a given value.

Two things in there look odd until you know why:

- `isValidUtf8` compares with `<=>`, not `=`. MySQL returns `NULL` for
  `CONVERT()` of invalid bytes, MariaDB returns `?`. With `=` the MySQL side
  turns "invalid" into "unknown", and `SUM()` drops it.
- `containsNonAscii` compares `OCTET_LENGTH` with `CHAR_LENGTH` instead of using
  a regular expression. MySQL 8.0.22 removed `REGEXP` on binary strings.

Do not detect in PHP. `mb_detect_encoding()` returns `ISO-8859-1` for arbitrary
bytes, since every byte sequence is valid there, and `iconv` disagrees with
MySQL's `latin1` on five byte values (section 5). Keeping detection and repair
in the same engine is what makes the counts and the `UPDATE`s agree.

### Guards the command applies

- **Column-level threshold** (`--threshold`, default 0.9): a column is only
  undoubled when that share of its non-ASCII rows carries the signature.
  Below it the column is reported with sample rows and left alone.
- **Capped undoubling**, three passes at most, affected rows printed per pass.
  Triple encoding exists; a fourth layer is suspicious, not automatic.
- **Serialized values** are never undoubled. Changing byte lengths breaks the
  `s:N:` prefixes.
- **Use a pre-migration dump as ground truth** if one exists. Convert and diff:
  that restores actual determinism and is usually the fastest path.

---

## 4. latin1_swedish_ci

It is a **collation**, not a charset — the bytes are unaffected, so everything
above applies unchanged. It was MySQL's server default from the beginning
(MySQL AB was Swedish) until MySQL 8.0 switched to `utf8mb4_0900_ai_ci`.

**Sorting is wrong for German.** In Swedish alphabet order `Å Ä Ö` come *after*
Z and `Ü` sorts as a variant of `Y`. So "Zürich" sorts before "Äpfel". Nobody
notices until someone scrolls an A–Z list.

**The real migration hazard: UNIQUE indexes.** `latin1_swedish_ci` treats `ö`
and `o` as different letters. `utf8mb4_unicode_ci` and `utf8mb4_general_ci` are
accent-insensitive — `ö = o = ó`. Previously distinct rows collide and
`CONVERT TO` fails with `Duplicate entry`. Check first:

```sql
SELECT LOWER(CONVERT(CONVERT(col USING binary) USING utf8mb4)) COLLATE utf8mb4_unicode_ci AS k,
       COUNT(*), GROUP_CONCAT(uid)
FROM t GROUP BY k HAVING COUNT(*) > 1;
```

Same applies to `WHERE`: exact lookups may now return extra rows.

### Find everything still on latin1

Table defaults and column-level overrides drift apart — check both:

```sql
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION NOT LIKE 'utf8mb4%';

SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME NOT LIKE 'utf8mb4%';
```

Convert the whole schema in one go — a half-migrated DB throws
`Illegal mix of collations` on any JOIN across converted and unconverted tables.

### Then fix the defaults

Set `character_set_server = utf8mb4` and `collation_server = utf8mb4_unicode_ci`
in the server config, otherwise the next extension that creates a table gets
`latin1_swedish_ci` again.

**Target collation:** TYPO3 defaults to `utf8mb4_unicode_ci` (UCA 4.0) — fine
and portable. MySQL 8: `utf8mb4_0900_ai_ci` is faster with a newer UCA.
MariaDB 10.10+: `utf8mb4_uca1400_ai_ci`. Do not mix across tables.

---

## 5. The three ALTER variants

MySQL's conversion is **always correct for its assumption** — it reads every
byte as cp1252 and re-encodes deterministically. It is never "sometimes wrong".
What is wrong is the premise. A faithful conversion of a lie is exactly how
case 3 is manufactured.

```sql
-- 1. metadata only, existing columns untouched
ALTER TABLE t DEFAULT CHARACTER SET utf8mb4;

-- 2. real transcoding of every byte
ALTER TABLE t CONVERT TO CHARACTER SET utf8mb4;
ALTER TABLE t MODIFY col TEXT CHARACTER SET utf8mb4;

-- 3. re-declare, keep bytes verbatim
ALTER TABLE t MODIFY col BLOB;
ALTER TABLE t MODIFY col TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Variant 1 catches people out constantly — it only affects *future* columns.
Data looks unchanged, so it feels like it worked.

| classification | variant |
|---|---|
| genuinely cp1252 (case 2) | **2** |
| already UTF-8 / fake latin1 (case 1) | **3** — anything else double encodes |
| double encoded (case 3) | per-row `UPDATE` on the binary column, then 3 |
| utf8 (mb3) → utf8mb4 | **2**, safe — mb3 is a subset |

Variant 3 works because conversion to and from a binary type is defined as
byte-preserving: you pass through a type that has no charset opinion.

### Side effects of variant 2

- **Type promotion:** `TEXT` → `MEDIUMTEXT`, `VARCHAR(n)` grows its byte
  allocation, because max bytes per character goes 1 → 4. Harmless, but shows up
  as schema diffs, and TYPO3's `DatabaseAnalyzer` will want to shrink them back
  to whatever `ext_tables.sql` declares. Align that file or fight it forever.
- **Index prefixes** scale the same way: `KEY (title(255))` needs 255 bytes on
  latin1, 1020 on utf8mb4 → breaks the old 767-byte InnoDB limit on pre-5.7 with
  COMPACT row format. Hence the `varchar(191)` era. Non-issue on MySQL 8 /
  MariaDB 10.2+ with DYNAMIC row format.

### MySQL vs PHP discrepancy

cp1252 leaves five byte positions undefined: `0x81 0x8D 0x8F 0x90 0x9D`. MySQL's
latin1 maps them to U+0081 etc. — a complete, reversible 256-byte mapping. PHP's
`iconv('Windows-1252', …)` rejects them; with `//IGNORE` it silently drops them.
So a PHP repair and a SQL repair can disagree on exactly those bytes. Rare, but
it explains unexplained diffs.

**Consolation:** variant 2 applied wrongly is *reversible* — undoing it is just
the cp1252 round-trip. Nothing is lost, as long as you notice before a second
migration stacks another layer on top.

---

## 6. Working from a dump

A dump can add **one** more encoding layer, and that layer is reversible — so
nothing is ever lost. The risk is not corruption, it is silently shifting which
case you are in between file and database.

### Two declarations govern it

```bash
head -40 dump.sql | grep -i 'SET NAMES\|character_set_client'
grep -m5 -i 'CHARACTER SET\|DEFAULT CHARSET' dump.sql
```

The header (`/*!40101 SET NAMES latin1 */;`) tells the importing server how to
read the file's bytes. The `CREATE TABLE … DEFAULT CHARSET=…` recreates the
columns. **If the two agree, import is pure pass-through** — same rule as ever.

| dump header | columns in dump | effect on import |
|---|---|---|
| latin1 | latin1 | pass-through, faithful |
| utf8mb4 | utf8mb4 | pass-through, faithful |
| latin1 | utf8mb4 | transcodes → adds a layer |
| utf8mb4 | latin1 | transcodes down → adds a layer |

### Check the file before importing

```bash
iconv -f UTF-8 -t UTF-8 dump.sql -o /dev/null   # silent = file is valid UTF-8
grep -c $'\xc3\x83' dump.sql                    # count "Ã" sequences
```

- header latin1 + valid UTF-8 → classic fake-latin1, fully intact
- header latin1 + not valid UTF-8 → genuine legacy single-byte content
- header utf8mb4 + heavy `C3 83` density → double encoding baked in at dump time
- header utf8mb4 + valid UTF-8, no `Ã` cluster → clean

### Import faithfully, then verify

Do **not** pass `--default-character-set` to the client — it overrides the header
and is the most common way people add a layer during import.

```bash
mysql scratch_db < dump.sql
```

Prove it rather than trusting it:

```bash
grep -o "Straße[^']\{0,20\}" dump.sql | head -1 | hexdump -C | head -2
```
```sql
SELECT HEX(bodytext) FROM tt_content WHERE uid = 123;
```

If they match, the import was byte-faithful. If not: drop the scratch DB, fix the
header handling, reimport. Never patch the difference afterwards.

Import into a scratch DB **with the original charsets from the dump**,
unmodified. Pre-converting the schema to utf8mb4 during import is the exact move
that manufactures double encoding.

### When dumping out

If the schema says latin1 but the bytes are already UTF-8, dump with
`--default-character-set=latin1` to get raw bytes out. Dumping with utf8mb4
against a lying schema makes mysqldump transcode bytes that were already correct.

---

## 7. Why nothing existing was reused

`stucki/typo3-charset-converter` handles the fake-latin1 case, and only that:
it re-declares through a binary type and restores the schema afterwards, but
does not look at the bytes, so a column with genuine cp1252 or double-encoded
rows comes out unchanged. The older TYPO3 extensions (`sfdbutf8`,
`convert2utf8`, `sm_charsethelper`) set collations without converting or date
from TYPO3 4.x. Core stays out of it deliberately, and TYPO3 v14 removed most of
`CharsetConverter`'s conversion helpers, so nothing can be built on that class.

The PHP libraries (`kit-jotform/php-ftfy`, `neitanod/forceutf8`) decide per
string and return the fixed string. They give no per-row audit trail, they fetch
every row into PHP, and `iconv` underneath them disagrees with MySQL's `latin1`
on five bytes. Deciding per column in SQL avoids all three.

---

## 8. Procedure

The command covers inventory, classification, review material, row repair,
schema conversion and the verification rerun. What is left is around it.

1. **Work on a copy.** Scratch DB, original charsets from the dump, unmodified
   (section 6).
2. **Verify the import was byte-faithful**: hexdump of the dump against
   `HEX()` in the database.
3. **Analyze**: `typo3 schnitzler:database-charset-repair:utf8mb4 --report
   review.csv`. Read-only. The plan table is the inventory, the CSV holds every
   double-encoded row with before and after.
4. **Review** the `(review)` columns and the short strings in the CSV. Lower
   `--threshold` together with `--table` where the samples say it is real.
   Resolve every `manual` column by hand; the command will not guess there.
5. **Check UNIQUE collisions** before an accent-insensitive collation
   (section 4). The command has no pre-check: a collision fails the table's
   repair with `Duplicate entry` and rolls the schema back.
6. **Repair**: same command with `--fix`. Per table: widen to binary, row
   `UPDATE`s, re-declare as utf8mb4, then `ALTER DATABASE`.
7. **Normalize to NFC** separately if needed. macOS-pasted content arrives NFD
   (`ä` = `a` + combining diaeresis): identical-looking, different bytes,
   breaks `WHERE title = ?` and creates phantom duplicates in the UNIQUE check.
8. **Fix connection and server defaults** (section 2 and 4), align
   `config/system/settings.php` and `ext_tables.sql`.
9. **Rerun the command.** It should report nothing to do and exit zero.

### Landmines

- **PHP-serialized values** (`tt_content.pi_flexform`, `sys_registry`, …):
  changing byte length inside a serialized string without fixing the length
  prefix breaks it. The command counts them and never undoubles them; if they
  are mangled, they need a PHP-side fix that re-serializes. Plain JSON is safe.
- **BLOB columns** that later became TEXT: a historic TYPO3 pattern
  (TS templates in 4.1 → 4.2) where latin1 content hid inside a BLOB, survived
  a DB conversion untouched, and then got reinterpreted as UTF-8 on the type
  change. Check BLOB/TEXT columns separately.
- **Half-migrated schema** → `Illegal mix of collations` on JOINs.
