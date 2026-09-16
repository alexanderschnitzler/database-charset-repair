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

namespace Schnitzler\DatabaseCharsetRepair\Infrastructure\Database;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnStats;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\Utility\ByteSql;
use TYPO3\CMS\Core\Database\Connection;

/**
 * The read-only side: which columns are in scope and what their bytes look like. Everything
 * runs as SELECT because there a lossy CONVERT() only warns; nothing here writes.
 *
 * Stateless, the connection is passed per call.
 */
final class TableAnalyzer
{
    /**
     * Splits a table's columns into the ones this extension handles and the ones it deliberately
     * leaves alone.
     *
     * Columns without a charset (numbers, dates, BINARY/BLOB) are dropped silently. Columns
     * declared `ascii` are returned under `skipped` so the command can say so: that charset is a
     * choice someone made, not a migration leftover. Everything else is returned under
     * `columns`, keyed by column name.
     *
     * @return array{columns: array<string, Column>, skipped: list<string>}
     */
    public function columnsInScope(Table $table): array
    {
        $columns = [];
        $skipped = [];
        foreach ($table->getColumns() as $column) {
            $charset = $column->getCharset();
            if ($charset === null) {
                continue;
            }
            if ($charset === 'ascii') {
                $skipped[] = $column->getName();
                continue;
            }
            $columns[$column->getName()] = $column;
        }

        return ['columns' => $columns, 'skipped' => $skipped];
    }

    /**
     * Counts, per column, how many rows match each byte predicate.
     *
     * One aggregate SELECT for the whole table: COUNT(*) plus four SUM()s per column (non-ASCII,
     * invalid UTF-8, double encoded, double encoded and serialized). Result aliases are derived
     * from the column names, so a name that is not a plain identifier is hashed. An empty table
     * yields all-zero stats.
     *
     * @param array<string, Column> $columns keyed by column name, as columnsInScope() returns them
     *
     * @return array<string, ColumnStats> keyed by column name
     */
    public function collectStats(Connection $connection, string $tableName, array $columns): array
    {
        $select = ['COUNT(*) AS total_rows'];
        foreach (array_keys($columns) as $name) {
            $bytes = ByteSql::bytesOf($connection->quoteSingleIdentifier($name));
            $alias = $this->resultAlias($name);
            $select[] = 'SUM(' . ByteSql::containsNonAscii($bytes) . ') AS ' . $alias . '__nonascii';
            $select[] = 'SUM(NOT ' . ByteSql::isValidUtf8($bytes) . ') AS ' . $alias . '__invalid';
            $select[] = 'SUM(' . ByteSql::isDoubleEncoded($bytes) . ') AS ' . $alias . '__doubled';
            $select[] = 'SUM(' . ByteSql::isDoubleEncoded($bytes) . ' AND ' . ByteSql::isPhpSerialized($bytes) . ') AS ' . $alias . '__doubled_serialized';
        }

        $row = $connection->fetchAssociative('SELECT ' . implode(', ', $select) . ' FROM ' . $connection->quoteSingleIdentifier($tableName));
        if ($row === false) {
            $row = [];
        }

        $result = [];
        foreach (array_keys($columns) as $name) {
            $alias = $this->resultAlias($name);
            $result[$name] = new ColumnStats(
                rows: (int)($row['total_rows'] ?? 0),
                nonascii: (int)($row[$alias . '__nonascii'] ?? 0),
                invalid: (int)($row[$alias . '__invalid'] ?? 0),
                doubled: (int)($row[$alias . '__doubled'] ?? 0),
                doubledSerialized: (int)($row[$alias . '__doubled_serialized'] ?? 0),
            );
        }

        return $result;
    }

    /**
     * Lists the double-encoded rows of one column with the value as it is and as it would be
     * after one encoding layer is removed, both decoded to UTF-8 text.
     *
     * Serialized rows are included: this is what the CSV report and the review samples show,
     * not what the repair rewrites. `uid` is the row's uid when the table has that column and
     * an empty string otherwise. $limit caps the row count, $truncate cuts both values to that
     * many characters; the report passes neither, the review output passes both.
     *
     * @return list<array{uid: string, before: string, after: string}>
     */
    public function doubleEncodedRows(Connection $connection, string $tableName, string $columnName, bool $hasUid, int|null $limit = null, int|null $truncate = null): array
    {
        $bytes = ByteSql::bytesOf($connection->quoteSingleIdentifier($columnName));
        $before = 'CONVERT(' . $bytes . ' USING utf8mb4)';
        $after = 'CONVERT(' . ByteSql::withOneLayerRemoved($bytes) . ' USING utf8mb4)';
        if ($truncate !== null) {
            $before = 'LEFT(' . $before . ', ' . $truncate . ')';
            $after = 'LEFT(' . $after . ', ' . $truncate . ')';
        }
        $sql = 'SELECT ' . ($hasUid ? 'uid' : '\'\' AS uid') . ', ' . $before . ' AS before_value, ' . $after . ' AS after_value'
            . ' FROM ' . $connection->quoteSingleIdentifier($tableName) . ' WHERE ' . ByteSql::isDoubleEncoded($bytes)
            . ($limit !== null ? ' LIMIT ' . $limit : '');

        $rows = [];
        foreach ($connection->fetchAllAssociative($sql) as $row) {
            $rows[] = ['uid' => (string)$row['uid'], 'before' => (string)$row['before_value'], 'after' => (string)$row['after_value']];
        }

        return $rows;
    }

    /** A result alias for the column: the name itself when it is a plain identifier, its md5 otherwise. */
    private function resultAlias(string $columnName): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $columnName) === 1 ? $columnName : md5($columnName);
    }
}
