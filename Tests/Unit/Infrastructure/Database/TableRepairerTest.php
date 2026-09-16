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

namespace Schnitzler\DatabaseCharsetRepair\Tests\Unit\Infrastructure\Database;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnPlan;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnStats;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableRepairer;

/** The DDL builder is pure PHP over the platform, so the shape of every statement is pinned here without a database. */
final class TableRepairerTest extends TestCase
{
    private const COLLATION = 'utf8mb4_unicode_ci';

    #[Test]
    public function varcharWidensToFourTimesTheLengthAsVarbinary(): void
    {
        $statements = $this->build(['name' => self::column(Types::STRING, 255)]);

        self::assertSame('ALTER TABLE `t` MODIFY `name` VARBINARY(1020) DEFAULT \'\' NOT NULL', $statements['widen']);
    }

    #[Test]
    public function varcharBeyondVarbinaryRangeAndTextWidenToBlob(): void
    {
        $statements = $this->build(['long' => self::column(Types::STRING, 20000), 'body' => self::column(Types::TEXT, null)]);

        // DBAL picks the BLOB flavour from the length: a TEXT is introspected with 65535, four times that is a MEDIUMBLOB.
        self::assertSame('ALTER TABLE `t` MODIFY `long` MEDIUMBLOB NOT NULL, MODIFY `body` LONGBLOB NOT NULL', $statements['widen']);
    }

    #[Test]
    public function charLosesItsFixedWidthWhileWidened(): void
    {
        $statements = $this->build(['code' => self::column(Types::STRING, 10, fixed: true)]);

        self::assertSame('ALTER TABLE `t` MODIFY `code` VARBINARY(40) DEFAULT \'\' NOT NULL', $statements['widen']);
        self::assertStringContainsString('`code` CHAR(10) CHARACTER SET utf8mb4 DEFAULT \'\' NOT NULL COLLATE `utf8mb4_unicode_ci`', $statements['redeclare']);
    }

    #[Test]
    public function enumGetsNoBinaryDetour(): void
    {
        $statements = $this->build(['state' => self::column(Types::ENUM, null, values: ['a', 'b'])]);

        self::assertNull($statements['widen']);
        self::assertSame(
            'ALTER TABLE `t` MODIFY `state` ENUM(\'a\', \'b\') CHARACTER SET utf8mb4 DEFAULT \'\' NOT NULL COLLATE `utf8mb4_unicode_ci`, DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $statements['redeclare'],
        );
    }

    #[Test]
    public function redeclareCarriesTheTargetCharsetAndRollbackTheOriginal(): void
    {
        $statements = $this->build(['name' => self::column(Types::STRING, 255), 'body' => self::column(Types::TEXT, null)]);

        self::assertSame(
            'ALTER TABLE `t` MODIFY `name` VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT \'\' NOT NULL COLLATE `utf8mb4_unicode_ci`'
            . ', MODIFY `body` LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`'
            . ', DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $statements['redeclare'],
        );
        self::assertSame(
            'ALTER TABLE `t` MODIFY `name` VARCHAR(255) CHARACTER SET latin1 DEFAULT \'\' NOT NULL COLLATE `latin1_swedish_ci`'
            . ', MODIFY `body` LONGTEXT CHARACTER SET latin1 NOT NULL COLLATE `latin1_swedish_ci`',
            $statements['rollback'],
        );
    }

    /**
     * @param array<string, Column> $columns
     *
     * @return array{widen: ?string, redeclare: string, rollback: string}
     */
    private function build(array $columns): array
    {
        $plans = [];
        foreach ($columns as $name => $column) {
            $plans[$name] = ColumnPlan::decide($column, new ColumnStats(1, 0, 0, 0, 0), 0.9, self::COLLATION);
        }

        return (new TableRepairer())->buildAlterStatements(new MySQLPlatform(), 't', $plans, self::COLLATION);
    }

    /**
     * @param list<string> $values
     */
    private static function column(string $type, int|null $length, bool $fixed = false, array $values = []): Column
    {
        return new Column('c', Type::getType($type), [
            'length' => $length,
            'fixed' => $fixed,
            'values' => $values,
            'notnull' => true,
            'default' => '',
            'platformOptions' => ['charset' => 'latin1', 'collation' => 'latin1_swedish_ci'],
        ]);
    }
}
