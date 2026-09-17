<?php

declare(strict_types=1);

/*
 * This file is part of the "Database Charset Repair" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2026-2026 Alexander Schnitzler <git@alexanderschnitzler.de>, Schnitzler Softwarelösungen
 */

namespace Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\Utility;

/**
 * Builds the SQL fragments that inspect and rewrite the raw bytes of a column. Every predicate
 * operates on a binary expression, usually the one bytesOf() returns, and every method only
 * returns SQL text: nothing here touches a connection, so the fragments compose freely into
 * SELECT aggregates, WHERE clauses and UPDATE statements.
 *
 * Identifiers are passed in already quoted. The two UPDATE builders emit complete statements,
 * their WHERE predicates are exposed separately so a dry run can count the rows they would hit.
 *
 * Read Documentation/Runbook.md before changing a predicate: MySQL and MariaDB differ in what
 * CONVERT() returns for invalid bytes, and MySQL 8.0.22+ refuses REGEXP on binary strings.
 */
final class ByteSql
{
    /**
     * The column's bytes as they are stored, detached from the declared charset.
     *
     * `CONVERT(col USING binary)` reinterprets the value without transcoding it, which is the
     * starting point of every predicate below: the declared charset is exactly what cannot be
     * trusted, the bytes can.
     */
    public static function bytesOf(string $quotedColumn): string
    {
        return 'CONVERT(' . $quotedColumn . ' USING binary)';
    }

    /**
     * True when the bytes are well-formed UTF-8.
     *
     * Decodes the bytes as utf8mb4 and encodes the result back to binary; only valid UTF-8
     * survives that round trip unchanged. The comparison is NULL-safe (`<=>`) on purpose:
     * MySQL yields NULL for CONVERT() of invalid bytes where MariaDB yields '?', and a plain `=`
     * would turn "invalid" into "unknown", which SUM() silently ignores.
     */
    public static function isValidUtf8(string $bytes): string
    {
        return '(CONVERT(CONVERT(' . $bytes . ' USING utf8mb4) USING binary) <=> ' . $bytes . ')';
    }

    /**
     * True when at least one byte is >= 0x80, i.e. the value is not pure ASCII.
     *
     * Invalid UTF-8 always involves such a byte, so invalid values qualify outright. Valid UTF-8
     * qualifies as soon as it holds a multi-byte sequence, which shows as more bytes than
     * characters. No REGEXP: MySQL 8.0.22+ refuses regular expressions on binary strings.
     */
    public static function containsNonAscii(string $bytes): string
    {
        return '(NOT ' . self::isValidUtf8($bytes) . ' OR OCTET_LENGTH(' . $bytes . ') <> CHAR_LENGTH(CONVERT(' . $bytes . ' USING utf8mb4)))';
    }

    /**
     * The bytes with one encoding layer removed: decoded as UTF-8, re-encoded as cp1252.
     *
     * A double-encoded "ä" is stored as C3 83 C2 A4, which reads as "Ã¤" in UTF-8; encoding
     * that in cp1252 gives back C3 A4, the UTF-8 "ä". MySQL's latin1 is really cp1252 and maps
     * all 256 bytes reversibly, which is why it is used instead of PHP's iconv.
     */
    public static function withOneLayerRemoved(string $bytes): string
    {
        return 'CONVERT(CONVERT(CONVERT(' . $bytes . ' USING utf8mb4) USING latin1) USING binary)';
    }

    /**
     * True when the bytes carry the double-encoding signature.
     *
     * All four conditions must hold: the value is valid non-ASCII UTF-8; every character fits
     * cp1252 (removing a layer and putting it back reproduces the value); and the value with one
     * layer removed is itself valid non-ASCII UTF-8. Short strings can pass by accident ("São"
     * does not, "Ã¤" does), which is why a column is only undoubled above a threshold.
     */
    public static function isDoubleEncoded(string $bytes): string
    {
        $down = self::withOneLayerRemoved($bytes);

        return '(' . self::isValidUtf8($bytes) . ' AND ' . self::containsNonAscii($bytes)
            . ' AND CONVERT(CONVERT(' . $down . ' USING latin1) USING utf8mb4) = CONVERT(' . $bytes . ' USING utf8mb4)'
            . ' AND ' . self::isValidUtf8($down) . ' AND ' . self::containsNonAscii($down) . ')';
    }

    /**
     * True when the value starts like a PHP serialized array, object or string.
     *
     * Undoubling changes byte lengths and would break the `s:N:` length prefixes inside, so
     * such rows are counted but never rewritten.
     */
    public static function isPhpSerialized(string $bytes): string
    {
        return '(LEFT(' . $bytes . ', 2) IN (\'a:\', \'O:\', \'s:\'))';
    }

    /**
     * UPDATE that transcodes rows whose bytes are genuinely in $sourceCharset (runbook case 2,
     * "genuine legacy") to UTF-8.
     *
     * Reads the column as $sourceCharset, whatever it is (latin1, latin2, cp1251, ...): the
     * declared charset, or the one --assume names for a utf8-family column that lost it.
     * Converts to utf8mb4 and stores the bytes. Only rows matching needsTranscoding() are
     * touched, so rows that are already UTF-8 (fake
     * latin1) stay as they are. Meant to run on the column while it is binary and with strict
     * mode off, because a lossy CONVERT() inside an UPDATE is otherwise an error.
     */
    public static function transcodeUpdate(string $quotedTable, string $quotedColumn, string $sourceCharset): string
    {
        return 'UPDATE ' . $quotedTable
            . ' SET ' . $quotedColumn . ' = CONVERT(CONVERT(CONVERT(' . $quotedColumn . ' USING ' . $sourceCharset . ') USING utf8mb4) USING binary)'
            . ' WHERE ' . self::needsTranscoding($quotedColumn);
    }

    /** WHERE predicate of transcodeUpdate(): the column's bytes are not valid UTF-8. */
    public static function needsTranscoding(string $quotedColumn): string
    {
        return 'NOT ' . self::isValidUtf8(self::bytesOf($quotedColumn));
    }

    /**
     * UPDATE that removes one encoding layer from double-encoded rows (runbook case 3).
     *
     * Replaces the column with withOneLayerRemoved() for every row matching needsUndoubling().
     * One layer per execution: a triple-encoded row needs a second run, which is why the
     * repairer loops until no row is affected.
     */
    public static function undoubleUpdate(string $quotedTable, string $quotedColumn): string
    {
        return 'UPDATE ' . $quotedTable
            . ' SET ' . $quotedColumn . ' = ' . self::withOneLayerRemoved(self::bytesOf($quotedColumn))
            . ' WHERE ' . self::needsUndoubling($quotedColumn);
    }

    /** WHERE predicate of undoubleUpdate(): double encoded and not a PHP serialized value. */
    public static function needsUndoubling(string $quotedColumn): string
    {
        $bytes = self::bytesOf($quotedColumn);

        return self::isDoubleEncoded($bytes) . ' AND NOT ' . self::isPhpSerialized($bytes);
    }
}
