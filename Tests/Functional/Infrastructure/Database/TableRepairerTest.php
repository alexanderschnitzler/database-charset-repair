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
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnPlan;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableAnalyzer;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableRepairer;
use Schnitzler\DatabaseCharsetRepair\Tests\Functional\FixtureTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class TableRepairerTest extends FixtureTestCase
{
    private const COLLATION = 'utf8mb4_unicode_ci';

    #[Test]
    public function dryRunStatementsListsDdlAndCountedUpdatesWithoutChangingAnything(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableRepairer/dry_run_statements.sql');
        $table = 'tx_databasecharsetrepair_dryrun';

        $lines = (new TableRepairer())->dryRunStatements($this->connection(), $table, $this->plans($table, 'a_varchar', 'a_text'), self::COLLATION);

        self::assertCount(6, $lines);
        self::assertStringStartsWith('ALTER TABLE `' . $table . '` MODIFY `a_varchar` VARBINARY(200)', $lines[0]);
        self::assertStringStartsWith('-- 1 row(s): UPDATE `' . $table . '` SET `a_varchar`', $lines[1]);
        self::assertStringStartsWith('-- 2 row(s), up to 3 passes: UPDATE `' . $table . '` SET `a_varchar`', $lines[2], 'the serialized row is not counted');
        self::assertStringStartsWith('-- 1 row(s): UPDATE `' . $table . '` SET `a_text`', $lines[3]);
        self::assertStringStartsWith('-- 2 row(s), up to 3 passes: UPDATE `' . $table . '` SET `a_text`', $lines[4]);
        self::assertStringContainsString('CHARACTER SET utf8mb4', $lines[5]);
        self::assertSame(['a_varchar' => 'latin1_swedish_ci', 'a_text' => 'latin1_swedish_ci'], $this->collationsOf($table, 'a_varchar', 'a_text'));
    }

    #[Test]
    public function repairFixesAllThreeFailureModesAndRedeclares(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableRepairer/repair.sql');
        $table = 'tx_databasecharsetrepair_repair';
        $output = new BufferedOutput();
        $sqlMode = $this->connection()->fetchOne('SELECT @@SESSION.sql_mode');

        $ok = (new TableRepairer())->repair($this->connection(), $output, $table, $this->plans($table, 'a_varchar', 'a_text'), self::COLLATION);

        self::assertTrue($ok, $output->fetch());
        $expected = [
            1 => '4173636969546578742030', // ASCII: untouched
            2 => 'C3A4',                   // fake latin1, already UTF-8: untouched
            3 => 'C3A4',                   // genuine cp1252 E4: transcoded
            4 => 'C3A4',                   // double encoded: one layer removed
            5 => 'C3A4',                   // triple encoded: two layers removed
            6 => '53C3A36F',               // "São": undecidable short string, untouched
            7 => '733A343A22C383C2A4223B', // PHP serialized: excluded, untouched
        ];
        foreach ($this->hexOf($table, 'a_varchar', 'a_text') as $uid => $row) {
            self::assertSame($expected[$uid], $row['a_varchar'], 'a_varchar uid ' . $uid);
            self::assertSame($expected[$uid], $row['a_text'], 'a_text uid ' . $uid);
        }
        self::assertSame(['a_varchar' => self::COLLATION, 'a_text' => self::COLLATION], $this->collationsOf($table, 'a_varchar', 'a_text'));
        self::assertSame($sqlMode, $this->connection()->fetchOne('SELECT @@SESSION.sql_mode'), 'strict mode restored');
    }

    #[Test]
    public function repairTranscodesInvalidRowsOfAUtf8ColumnFromTheAssumedCharset(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableRepairer/repair_assumed_schema.sql');
        $table = 'tx_databasecharsetrepair_assumed';
        $utf8Column = $this->connection()->createSchemaManager()->introspectTableByUnquotedName($table)->getColumn('a_text');
        $this->loadFixture(__DIR__ . '/Fixtures/TableRepairer/repair_assumed_bytes.sql');
        $analyzer = new TableAnalyzer();
        $stats = $analyzer->collectStats($this->connection(), $table, ['a_text' => $utf8Column]);
        $plan = ColumnPlan::decide($utf8Column, $stats['a_text'], 0.5, self::COLLATION, 'latin1');
        $output = new BufferedOutput();

        self::assertSame('redeclare +transcode (assumed latin1)', $plan->label());
        $ok = (new TableRepairer())->repair($this->connection(), $output, $table, ['a_text' => $plan], self::COLLATION);

        self::assertTrue($ok, $output->fetch());
        self::assertSame([
            1 => ['a_text' => 'C3A4'],                   // real latin1 ä: transcoded
            2 => ['a_text' => 'C3A4'],                   // already UTF-8: untouched
            3 => ['a_text' => '4DC3BC6C6C6572'],         // real latin1 Müller: transcoded
            4 => ['a_text' => '4D69746172626569746572'], // ASCII: untouched
        ], $this->hexOf($table, 'a_text'));
        self::assertSame(['a_text' => self::COLLATION], $this->collationsOf($table, 'a_text'));
    }

    #[Test]
    public function failedRepairRollsBackTheSchemaAndRestoresStrictMode(): void
    {
        $this->loadFixture(__DIR__ . '/Fixtures/TableRepairer/repair_collision.sql');
        $table = 'tx_databasecharsetrepair_collision';
        $output = new BufferedOutput();
        $sqlMode = $this->connection()->fetchOne('SELECT @@SESSION.sql_mode');

        $ok = (new TableRepairer())->repair($this->connection(), $output, $table, $this->plans($table, 'a_varchar'), self::COLLATION);

        self::assertFalse($ok);
        self::assertStringContainsString('Rolling back schema', $output->fetch());
        self::assertSame([1 => ['a_varchar' => 'C3A4'], 2 => ['a_varchar' => 'E4']], $this->hexOf($table, 'a_varchar'), 'the failing UPDATE is atomic');
        self::assertSame(['a_varchar' => 'latin1_swedish_ci'], $this->collationsOf($table, 'a_varchar'));
        self::assertSame('varchar(50)', $this->connection()->fetchOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'a_varchar'],
        ), 'no longer VARBINARY');
        self::assertSame($sqlMode, $this->connection()->fetchOne('SELECT @@SESSION.sql_mode'), 'strict mode restored');
    }

    /**
     * Plans the way the command builds them: real stats, threshold 0.5.
     *
     * @param non-empty-string $table
     *
     * @return array<string, ColumnPlan>
     */
    private function plans(string $table, string ...$columns): array
    {
        $analyzer = new TableAnalyzer();
        $introspected = $this->connection()->createSchemaManager()->introspectTableByUnquotedName($table);
        $inScope = $analyzer->columnsInScope($introspected)['columns'];
        $stats = $analyzer->collectStats($this->connection(), $table, $inScope);

        $plans = [];
        foreach ($columns as $name) {
            $plans[$name] = ColumnPlan::decide($inScope[$name], $stats[$name], 0.5, self::COLLATION);
        }

        return $plans;
    }
}
