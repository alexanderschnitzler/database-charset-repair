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

namespace Schnitzler\DatabaseCharsetRepair\Tests\Functional;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Helpers only, no data: every test loads the one fixture that holds exactly the rows it
 * asserts on. Assertions compare HEX() of the bytes and information_schema, never strings.
 */
abstract class FixtureTestCase extends FunctionalTestCase
{
    /**
     * Runs every statement of the SQL file. \`--\` comments are stripped first so a \`;\` inside
     * one does not split a statement; fixtures start with DROP TABLE IF EXISTS so they are
     * self-contained.
     */
    protected function loadFixture(string $path): void
    {
        $fixture = (string)preg_replace('/\s*--[^\n]*/', '', (string)file_get_contents($path));
        foreach (array_filter(array_map('trim', explode(';', $fixture)), static fn(string $statement): bool => $statement !== '') as $statement) {
            $this->connection()->executeStatement($statement);
        }
    }

    protected function connection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
    }

    /**
     * The given columns as upper-case hex per row, keyed by uid.
     *
     * @return array<int, array<string, string>>
     */
    protected function hexOf(string $table, string ...$columns): array
    {
        $select = array_map(static fn(string $column): string => 'HEX(' . $column . ') AS ' . $column, $columns);
        $rows = $this->connection()->executeQuery('SELECT uid, ' . implode(', ', $select) . ' FROM ' . $table . ' ORDER BY uid')->fetchAllAssociative();

        $hex = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            unset($row['uid']);
            $hex[$uid] = array_map(static fn(mixed $value): string => (string)$value, $row);
        }

        return $hex;
    }

    /**
     * Collation of the given columns as declared right now, keyed by column name.
     *
     * @return array<string, string>
     */
    protected function collationsOf(string $table, string ...$columns): array
    {
        $rows = $this->connection()->executeQuery(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?) ORDER BY ORDINAL_POSITION',
            [$table, $columns],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $collations = [];
        foreach ($rows as $row) {
            $collations[(string)$row['COLUMN_NAME']] = (string)$row['COLLATION_NAME'];
        }

        return $collations;
    }
}
