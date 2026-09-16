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

namespace Schnitzler\DatabaseCharsetRepair\Tests\Unit\Domain\Model;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnPlan;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnStats;
use TYPO3\CMS\Core\Database\Schema\Types\SetType;

final class ColumnPlanTest extends TestCase
{
    private const COLLATION = 'utf8mb4_unicode_ci';

    /**
     * @return iterable<string, array{0: Column, 1: ColumnStats, 2: float, 3: string, 4: bool, 5: bool}>
     */
    public static function decisions(): iterable
    {
        // label, needsRepair, isManual
        yield 'utf8mb4 column with target collation and clean bytes' => [
            self::column('utf8mb4', self::COLLATION), self::stats(nonascii: 3), 0.9, 'ok', false, false,
        ];
        yield 'utf8mb4 column with another collation' => [
            self::column('utf8mb4', 'utf8mb4_general_ci'), self::stats(), 0.9, 'redeclare', true, false,
        ];
        yield 'latin1 column with only valid UTF-8 bytes (fake latin1)' => [
            self::column(), self::stats(nonascii: 3), 0.9, 'redeclare', true, false,
        ];
        yield 'latin1 column with invalid bytes (genuine cp1252)' => [
            self::column(), self::stats(nonascii: 3, invalid: 3), 0.9, 'redeclare +transcode', true, false,
        ];
        yield 'double encoded above threshold' => [
            self::column(), self::stats(nonascii: 10, doubled: 9), 0.9, 'redeclare +undouble', true, false,
        ];
        yield 'double encoded exactly at threshold' => [
            self::column(), self::stats(nonascii: 2, doubled: 1), 0.5, 'redeclare +undouble', true, false,
        ];
        yield 'double encoded below threshold is reviewed, not undoubled' => [
            self::column(), self::stats(nonascii: 10, doubled: 8), 0.9, 'redeclare (review)', true, false,
        ];
        yield 'genuine legacy bytes and double encoded side by side' => [
            self::column(), self::stats(nonascii: 10, invalid: 1, doubled: 9), 0.9, 'redeclare +transcode +undouble', true, false,
        ];
        yield 'already utf8mb4 but double encoded below threshold' => [
            self::column('utf8mb4', self::COLLATION), self::stats(nonascii: 10, doubled: 1), 0.9, 'ok (review)', false, false,
        ];
        yield 'invalid bytes in a utf8 column' => [
            self::column('utf8', 'utf8_general_ci'), self::stats(nonascii: 1, invalid: 1), 0.9, 'manual (invalid bytes in a utf8 column)', false, true,
        ];
        yield 'invalid bytes in a utf8mb3 column' => [
            self::column('utf8mb3', 'utf8mb3_general_ci'), self::stats(nonascii: 1, invalid: 1), 0.9, 'manual (invalid bytes in a utf8mb3 column)', false, true,
        ];
        yield 'invalid bytes in a utf8mb4 column' => [
            self::column('utf8mb4', self::COLLATION), self::stats(nonascii: 1, invalid: 1), 0.9, 'manual (invalid bytes in a utf8mb4 column)', false, true,
        ];
        yield 'utf16 column, even with clean-looking bytes' => [
            self::column('utf16', 'utf16_general_ci'), self::stats(), 0.9, 'manual (fixed-width utf16 column, bytes cannot be classified)', false, true,
        ];
        yield 'ucs2 column' => [
            self::column('ucs2', 'ucs2_general_ci'), self::stats(nonascii: 1, invalid: 1), 0.9, 'manual (fixed-width ucs2 column, bytes cannot be classified)', false, true,
        ];
        yield 'enum with invalid bytes' => [
            self::column(type: Types::ENUM), self::stats(nonascii: 1, invalid: 1), 0.9, 'manual (invalid bytes in a latin1 column)', false, true,
        ];
        yield 'enum with non-ASCII bytes' => [
            self::column(type: Types::ENUM), self::stats(nonascii: 1), 0.9, 'manual (non-ASCII enum/set)', false, true,
        ];
        yield 'set with non-ASCII bytes' => [
            self::column(type: SetType::TYPE), self::stats(nonascii: 1), 0.9, 'manual (non-ASCII enum/set)', false, true,
        ];
        yield 'ASCII-only enum is redeclared without a binary detour' => [
            self::column(type: Types::ENUM), self::stats(), 0.9, 'redeclare', true, false,
        ];
    }

    #[Test]
    #[DataProvider('decisions')]
    public function decide(Column $column, ColumnStats $stats, float $threshold, string $label, bool $needsRepair, bool $isManual): void
    {
        $plan = ColumnPlan::decide($column, $stats, $threshold, self::COLLATION);

        self::assertSame($label, $plan->label());
        self::assertSame($needsRepair, $plan->needsRepair());
        self::assertSame($isManual, $plan->isManual());
        self::assertSame($column, $plan->column);
    }

    #[Test]
    public function enumOrSetIsExposedForTheRepairer(): void
    {
        self::assertTrue(ColumnPlan::decide(self::column(type: Types::ENUM), self::stats(), 0.9, self::COLLATION)->enumOrSet);
        self::assertTrue(ColumnPlan::decide(self::column(type: SetType::TYPE), self::stats(), 0.9, self::COLLATION)->enumOrSet);
        self::assertFalse(ColumnPlan::decide(self::column(), self::stats(), 0.9, self::COLLATION)->enumOrSet);
    }

    private static function column(string $charset = 'latin1', string $collation = 'latin1_swedish_ci', string $type = Types::STRING): Column
    {
        // TYPO3 registers its SET type through the ConnectionPool, which no unit test builds.
        if (!Type::hasType(SetType::TYPE)) {
            Type::addType(SetType::TYPE, SetType::class);
        }

        return new Column('c', Type::getType($type), ['platformOptions' => ['charset' => $charset, 'collation' => $collation]]);
    }

    private static function stats(int $rows = 10, int $nonascii = 0, int $invalid = 0, int $doubled = 0, int $doubledSerialized = 0): ColumnStats
    {
        return new ColumnStats($rows, $nonascii, $invalid, $doubled, $doubledSerialized);
    }
}
