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

namespace Schnitzler\DatabaseCharsetRepair\Tests\Functional\Infrastructure\Database;

use PHPUnit\Framework\Attributes\Test;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnStats;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableAnalyzer;
use Schnitzler\DatabaseCharsetRepair\Tests\Functional\FixtureTestCase;

final class TableAnalyzerTest extends FixtureTestCase
{
    #[Test]
    public function columnsInScopeKeepsCharsetColumnsAndSkipsDeliberateCharsets(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableAnalyzer/columns_in_scope.sql');
        $table = $this->connection()->createSchemaManager()->introspectTableByUnquotedName('tx_databasecharsetrepair_scope');

        ['columns' => $columns, 'skipped' => $skipped] = (new TableAnalyzer())->columnsInScope($table);

        // uid, a_binary, a_int and a_datetime have no charset and are dropped silently.
        self::assertSame(['a_latin1', 'a_utf8mb4', 'a_enum'], array_keys($columns));
        self::assertSame(['a_ascii'], $skipped);
    }

    #[Test]
    public function collectStatsCountsEachColumnOnItsOwnAndIgnoresNull(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableAnalyzer/collect_stats.sql');
        $analyzer = new TableAnalyzer();
        $table = $this->connection()->createSchemaManager()->introspectTableByUnquotedName('tx_databasecharsetrepair_stats');

        $stats = $analyzer->collectStats($this->connection(), 'tx_databasecharsetrepair_stats', $analyzer->columnsInScope($table)['columns']);

        self::assertEquals([
            'a' => new ColumnStats(rows: 5, nonascii: 4, invalid: 1, doubled: 2, doubledSerialized: 1),
            'b' => new ColumnStats(rows: 5, nonascii: 3, invalid: 1, doubled: 1, doubledSerialized: 0),
        ], $stats);
    }

    #[Test]
    public function doubleEncodedRowsListsBeforeAndAfterOneLayerRemovedSerializedIncluded(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableAnalyzer/double_encoded_rows.sql');

        $rows = (new TableAnalyzer())->doubleEncodedRows($this->connection(), 'tx_databasecharsetrepair_doubled', 'value', hasUid: true);

        self::assertSame([
            ['uid' => '2', 'before' => 'Ã¤', 'after' => 'ä'],
            ['uid' => '3', 'before' => 'ÃƒÂ¤', 'after' => 'Ã¤'],
            ['uid' => '4', 'before' => 's:4:"Ã¤";', 'after' => 's:4:"ä";'],
        ], $rows);
    }

    #[Test]
    public function doubleEncodedRowsCanBeLimitedAndTruncatedAndWorksWithoutUid(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableAnalyzer/double_encoded_rows.sql');

        $rows = (new TableAnalyzer())->doubleEncodedRows($this->connection(), 'tx_databasecharsetrepair_doubled_nouid', 'value', hasUid: false, limit: 1, truncate: 1);

        self::assertSame([['uid' => '', 'before' => 'Ã', 'after' => 'ä']], $rows);
    }
}
