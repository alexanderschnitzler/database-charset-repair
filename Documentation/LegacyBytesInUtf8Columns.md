# Legacy bytes in utf8 columns

The one case the command refuses by default, and what `--assume` does about it. Everything below
is about bytes, so everything is shown as bytes. One character carries the whole story:

```
             latin1 (cp1252)    UTF-8
ä            E4                 C3 A4
```

## 1. One column, two kinds of rows

A TYPO3 database that was latin1 for years rarely holds latin1 only. Row 1 was written by an
application that spoke latin1. Row 2 was written by one that pushed UTF-8 through a latin1
connection unchanged, which is the *fake latin1* of the [Runbook](Runbook.md), case 1.

```
column declared latin1
row 1   E4        server shows  ä      truthful
row 2   C3 A4     server shows  Ã¤     UTF-8 bytes wearing a latin1 label
```

Both rows are the same character. Only row 2 looks broken, so someone reaches for a converter.

## 2. The fork

There are two ways to move a latin1 column to utf8, and each is right for exactly one of the rows.

```
A  ALTER TABLE … CONVERT TO CHARACTER SET utf8          transcodes every row from latin1
row 1   E4       -> C3 A4          ä      correct
row 2   C3 A4    -> C3 83 C2 A4    Ã¤     double encoded: each byte transcoded on its own

B  binary detour: MODIFY … BLOB, then MODIFY … TEXT CHARACTER SET utf8      bytes untouched
row 1   E4       -> E4             ?      invalid UTF-8
row 2   C3 A4    -> C3 A4          ä      correct
```

A is what everybody tries first and how `Ã¤` gets into a database. B is what fake-latin1 fixers do,
and applied to a whole database it does it to the truthful columns too. The result of B is this
document's case: a column declared utf8 holding row 1 as a lone `E4`.

The command handles the mixed column itself, before either A or B was applied, row by row. That
is the `+transcode` plan: rows that are not valid UTF-8 are transcoded from the declared charset,
rows that already are stay as they are. Case 2b is the same column *after* B, with one piece of
information gone: the declaration no longer says `latin1`, so it no longer says what row 1 is.

## 3. Why the bytes still tell the rows apart

UTF-8 is self-describing. A lead byte announces how many continuation bytes follow, and every
continuation byte has the form `10xxxxxx`. No charset knowledge is needed to check that.

```
E4      = 1110 0100    lead byte of a 3-byte sequence, needs two 10xxxxxx bytes after it
        followed by end of string, or by 6C ("l" = 0110 1100): neither is a continuation
        -> invalid

C3 A4   = 1100 0011  1010 0100
        2-byte lead, then one continuation
        -> valid
```

So the column does not have to be treated as a whole. The predicate `NOT isValidUtf8(bytes)`
picks row 1 and leaves row 2 alone, exactly as it does for a latin1-declared column. What the
check cannot say is *which* single-byte charset row 1 is in. In every single-byte charset all 256
byte values are valid; `E4` is `ä` in latin1 and `д` in cp1251, and the bytes carry no vote.

A longer word, to show it is not a single-byte trick:

```
Müller  latin1   4D FC 6C 6C 65 72
        UTF-8    4D C3 BC 6C 6C 65 72

FC = 1111 1100, followed by 6C: not a continuation -> invalid -> transcode from latin1 -> C3 BC
```

## 4. What `--assume` does

The declaration cannot name the charset, so the option does. Everything else is the ordinary
`+transcode` repair: the column is widened to binary, one `UPDATE` rewrites the rows that fail the
check, the column is re-declared utf8mb4.

```
WHERE bytes are not valid UTF-8:
   CONVERT(CONVERT(CONVERT(col USING latin1) USING utf8mb4) USING binary)

row 1   E4       -> C3 A4     ä
row 2   C3 A4       untouched ä
```

The whole history in one line per stage:

```
years ago          latin1 column    E4 | C3 A4
after the detour   utf8 column      E4 | C3 A4      row 1 invalid now, row 2 right now
--assume latin1    utf8mb4 column   C3 A4 | C3 A4   both right, row 2 never rewritten
```

`--assume` only applies to columns declared `utf8`, `utf8mb3` or `utf8mb4`. A column declared
`latin1`, `latin2`, `cp1251` keeps using its own declaration; a truthful declaration beats a
global guess. ENUM and SET columns stay `manual`, their declaration would have to change too.

## 5. It is a guess: look at the samples

Because the bytes cannot confirm the charset, the command shows what the assumption makes of them
before anything is written. For every column it would transcode this way, the analysis prints
five rows, the bytes as hex and the text they become:

```
transcode samples for bodytext (invalid bytes read as latin1, before as hex):
  {"uid":"1","before":"E4","after":"ä"}
  {"uid":"3","before":"4DFC6C6C6572","after":"Müller"}
```

Read the `after` column like a human. If `Müller` and `Straße` come out, the assumption holds. If
the names of a German site come out Cyrillic, the wrong charset was assumed, and nothing has
happened yet. The same bytes under a different assumption:

```
E4 read as latin1    ä
E4 read as cp1251    д
E4 read as latin2    ä      (latin1 and latin2 agree on this byte, not on all)
```

A wrong assumption that was applied is not detectable afterwards: the rewritten rows are valid
UTF-8 then, indistinguishable from the rows that always were. So the usual order holds, with
more weight than elsewhere: dry run, read the samples, run on a scratch copy, then `--fix`.

```bash
# analysis only, prints the plan and the samples
php vendor/bin/typo3 schnitzler:database-charset-repair:utf8mb4 --assume latin1

# one column, once the samples read right
php vendor/bin/typo3 schnitzler:database-charset-repair:utf8mb4 --assume latin1 --column tt_content.bodytext --fix
```

## 6. Where such columns come from, and why you cannot make one today

The detour in section 2 is the mechanism, but a current server refuses to finish it. Measured on
MariaDB 10.4 and MySQL 8.4, planting `E4` and `4D FC 6C 6C 65 72` under a utf8 declaration:

| write path | strict mode (the default) | strict mode off |
|---|---|---|
| `INSERT` through a utf8mb4 connection | error `Incorrect string value` | MariaDB stores `3F` and `4D 3F 6C 6C 65 72` (`?` for the byte), MySQL stores `''` and `4D` (truncated at the byte) |
| `INSERT` through a binary connection (`SET NAMES binary`) | same error | same substitution or truncation |
| `MODIFY … BLOB`, then `MODIFY … TEXT CHARACTER SET utf8` | same error | same substitution or truncation |
| the same with `ALGORITHM=INPLACE`, `NOCOPY` or `INSTANT` | refused, "cannot change column type" | refused |

Two things follow. First, a column with legacy bytes under a utf8 label was written by a server
or a tool that did not check, and has survived since because nothing re-reads stored rows: not
`mysql_upgrade`, not a version upgrade in place, not a physical backup. Second, trying the
detour today to "just relabel" does not produce this case, it produces `?` or a truncated
string, which is data loss with no byte left to recover from. The command never re-declares
before the rows are valid for that reason.

For the same reason the functional test for this repair plants the bytes through a BLOB column
after introspecting the utf8 declaration: the state exists in the wild, it cannot be created
with SQL on the engines the extension supports.
