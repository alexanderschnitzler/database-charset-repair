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

namespace Schnitzler\DatabaseCharsetRepair\Tests\Functional\Application\Command;

use PHPUnit\Framework\Attributes\Test;
use Schnitzler\DatabaseCharsetRepair\Application\Command\Utf8mb4Command;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableAnalyzer;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableRepairer;
use Schnitzler\DatabaseCharsetRepair\Tests\Functional\FixtureTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** End-to-end smoke test through CommandTester; the services carry the precise assertions. */
final class Utf8mb4CommandTest extends FixtureTestCase
{
    private const TABLE = 'tx_databasecharsetrepair_command';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadFixture(__DIR__ . '/Fixtures/failure_modes.sql');
    }

    #[Test]
    public function analyzeOnlyReportsAndChangesNothing(): void
    {
        $tester = $this->runCommand(['--table' => [self::TABLE]]);

        // 3 of 6 non-ASCII rows are double encoded: below the default threshold, so review only.
        self::assertStringContainsString('redeclare +transcode (review)', $tester->getDisplay());
        self::assertStringContainsString('review samples for a_varchar', $tester->getDisplay());
        self::assertStringContainsString('skipped (deliberate charset): a_ascii', $tester->getDisplay());
        self::assertSame(['a_varchar' => 'latin1_swedish_ci', 'a_text' => 'latin1_swedish_ci'], $this->collationsOf(self::TABLE, 'a_varchar', 'a_text'));
        self::assertSame('E4', $this->hexOf(self::TABLE, 'a_varchar')[3]['a_varchar']);
    }

    #[Test]
    public function fixRepairsAllThreeFailureModes(): void
    {
        $tester = $this->runCommand(['--table' => [self::TABLE], '--fix' => true, '--threshold' => '0.5']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $expected = [
            1 => '4173636969546578742030', // ASCII: untouched
            2 => 'C3A4',                   // fake latin1, already UTF-8: untouched
            3 => 'C3A4',                   // genuine cp1252 E4: transcoded
            4 => 'C3A4',                   // double encoded: one layer removed
            5 => 'C3A4',                   // triple encoded: two layers removed
            6 => '53C3A36F',               // "São": undecidable short string, untouched
            7 => '733A343A22C383C2A4223B', // PHP serialized: excluded, untouched
        ];
        foreach ($this->hexOf(self::TABLE, 'a_varchar', 'a_text') as $uid => $row) {
            self::assertSame($expected[$uid], $row['a_varchar'], 'a_varchar uid ' . $uid);
            self::assertSame($expected[$uid], $row['a_text'], 'a_text uid ' . $uid);
        }
        self::assertSame(['a_varchar' => 'utf8mb4_unicode_ci', 'a_text' => 'utf8mb4_unicode_ci'], $this->collationsOf(self::TABLE, 'a_varchar', 'a_text'));
    }

    #[Test]
    public function columnOptionRepairsOnlyThatColumn(): void
    {
        $tester = $this->runCommand(['--column' => [self::TABLE . '.a_varchar'], '--fix' => true, '--threshold' => '0.5']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('a_text', $tester->getDisplay());
        self::assertSame(['a_varchar' => 'utf8mb4_unicode_ci', 'a_text' => 'latin1_swedish_ci'], $this->collationsOf(self::TABLE, 'a_varchar', 'a_text'));
        $row = $this->hexOf(self::TABLE, 'a_varchar', 'a_text')[3];
        self::assertSame('C3A4', $row['a_varchar']);
        self::assertSame('E4', $row['a_text']);
    }

    #[Test]
    public function columnOptionRefusesColumnOutOfScope(): void
    {
        $tester = $this->runCommand(['--column' => [self::TABLE . '.a_ascii', self::TABLE . '.nope']]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('a_ascii, nope', $tester->getDisplay());
    }

    #[Test]
    public function secondRunHasNothingToDo(): void
    {
        $this->runCommand(['--table' => [self::TABLE], '--fix' => true, '--threshold' => '0.5']);
        $tester = $this->runCommand(['--table' => [self::TABLE], '--fix' => true, '--threshold' => '0.5']);

        self::assertStringContainsString('Nothing to do.', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester(new Utf8mb4Command($this->get(ConnectionPool::class), new TableAnalyzer(), new TableRepairer()));
        $tester->execute($input);

        return $tester;
    }
}
