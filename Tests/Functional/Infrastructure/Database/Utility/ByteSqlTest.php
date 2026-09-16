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

namespace Schnitzler\DatabaseCharsetRepair\Tests\Functional\Infrastructure\Database\Utility;

use PHPUnit\Framework\Attributes\Test;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\Utility\ByteSql;
use Schnitzler\DatabaseCharsetRepair\Tests\Functional\FixtureTestCase;

/**
 * The predicates are the whole point of the extension and the one place MySQL and MariaDB
 * disagree (NULL vs '?' for CONVERT() of invalid bytes), so every byte pattern is pinned.
 */
final class ByteSqlTest extends FixtureTestCase
{
    #[Test]
    public function predicatesTellEveryBytePatternApart(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/ByteSql/predicates.sql');
        $bytes = ByteSql::bytesOf('value');

        $rows = $this->connection()->executeQuery(
            'SELECT uid, ' . ByteSql::isValidUtf8($bytes) . ' AS valid, ' . ByteSql::containsNonAscii($bytes) . ' AS nonascii, '
            . ByteSql::isDoubleEncoded($bytes) . ' AS doubled, ' . ByteSql::isPhpSerialized($bytes) . ' AS serialized'
            . ' FROM tx_databasecharsetrepair_predicates ORDER BY uid',
        )->fetchAllAssociative();

        $actual = [];
        foreach ($rows as $row) {
            $actual[(int)$row['uid']] = [(int)$row['valid'], (int)$row['nonascii'], (int)$row['doubled'], (int)$row['serialized']];
        }

        //                valid nonascii doubled serialized
        self::assertSame([
            1 => [1, 0, 0, 0], // ASCII
            2 => [1, 1, 0, 0], // UTF-8 "ä"
            3 => [0, 1, 0, 0], // cp1252 "ä"
            4 => [1, 1, 1, 0], // double encoded
            5 => [1, 1, 1, 0], // triple encoded
            6 => [1, 1, 0, 0], // "São": one layer down is 53 E3 6F, not valid UTF-8, so not doubled
            7 => [1, 1, 1, 1], // serialized with a double encoded payload
        ], $actual);
    }

    #[Test]
    public function withOneLayerRemovedUndoesExactlyOneEncoding(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/ByteSql/predicates.sql');

        $actual = $this->connection()->executeQuery(
            'SELECT uid, HEX(' . ByteSql::withOneLayerRemoved(ByteSql::bytesOf('value')) . ') AS down'
            . ' FROM tx_databasecharsetrepair_predicates WHERE uid IN (4, 5, 7) ORDER BY uid',
        )->fetchAllKeyValue();

        self::assertSame([
            4 => 'C3A4',                   // double encoded -> UTF-8 "ä"
            5 => 'C383C2A4',               // triple encoded -> double encoded
            7 => '733A343A22C3A4223B',     // the serialized payload shrinks, which is why such rows are never rewritten
        ], $actual);
    }
}
