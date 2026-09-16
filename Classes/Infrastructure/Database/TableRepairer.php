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

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnPlan;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\Utility\ByteSql;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;

/**
 * The write side: one table at a time, all affected columns in one statement per step.
 *
 * Row repairs run on a temporarily binary column with strict mode switched off, because there
 * a lossy CONVERT() would raise error 1300. DDL runs with strict mode on. Stateless, the
 * connection is passed per call.
 */
final class TableRepairer
{
    /** Undoubling removes one layer per pass; more than two layers is suspicious per the runbook, so stop at three. */
    public const MAX_UNDOUBLE_PASSES = 3;

    /**
     * Builds the three ALTER TABLE statements a repair needs, without running anything.
     *
     * `widen` re-declares every non-ENUM/SET column as VARBINARY at four times its length, or
     * as a BLOB when that exceeds 65535 or the length is unknown, so the row UPDATEs can store
     * any byte sequence without truncation: latin1 to UTF-8 grows to at most three bytes per
     * character and VARCHAR(n) utf8mb4 already reserves 4n bytes. It is null when every column
     * is an ENUM/SET, which only reach a plan with pure ASCII content and need no detour.
     * `redeclare` restores the original types with charset utf8mb4 and $collation and sets
     * the table default. `rollback` restores the original declarations verbatim; it is a real
     * rollback only because DDL never transcodes bytes.
     *
     * @param array<string, ColumnPlan> $plans keyed by column name, only plans with needsRepair()
     * @param non-empty-string $collation
     *
     * @return array{widen: ?string, redeclare: string, rollback: string}
     */
    public function buildAlterStatements(AbstractMySQLPlatform $platform, string $tableName, array $plans, string $collation): array
    {
        $table = $platform->quoteSingleIdentifier($tableName);
        $widen = [];
        $redeclare = [];
        $rollback = [];
        foreach ($plans as $name => $plan) {
            $quoted = $platform->quoteSingleIdentifier($name);
            $column = $plan->column;
            $original = $column->toArray();

            if (!$plan->enumOrSet) {
                $length = $column->getLength() === null ? null : $column->getLength() * 4;
                $binary = $original;
                $binary['type'] = Type::getType($length !== null && $length <= 65535 ? Types::BINARY : Types::BLOB);
                $binary['length'] = $length;
                $binary['fixed'] = false;
                unset($binary['charset'], $binary['collation']);
                $widen[] = $platform->getColumnDeclarationSQL($quoted, $binary);
            }

            $target = $original;
            $target['charset'] = ColumnPlan::TARGET_CHARSET;
            $target['collation'] = $collation;
            $redeclare[] = $platform->getColumnDeclarationSQL($quoted, $target);
            $rollback[] = $platform->getColumnDeclarationSQL($quoted, $original);
        }

        return [
            'widen' => $widen === [] ? null : 'ALTER TABLE ' . $table . ' MODIFY ' . implode(', MODIFY ', $widen),
            'redeclare' => 'ALTER TABLE ' . $table . ' MODIFY ' . implode(', MODIFY ', $redeclare)
                . ', DEFAULT CHARACTER SET ' . ColumnPlan::TARGET_CHARSET . ' COLLATE ' . $collation,
            'rollback' => 'ALTER TABLE ' . $table . ' MODIFY ' . implode(', MODIFY ', $rollback),
        ];
    }

    /**
     * The statements repair() would run, as SQL text for a dry run.
     *
     * Same order as repair(): widen, one line per row UPDATE, re-declare. Each UPDATE line is
     * prefixed with a comment holding the number of rows its WHERE currently matches, which
     * costs one COUNT(*) query per UPDATE; nothing is modified.
     *
     * @param array<string, ColumnPlan> $plans keyed by column name, only plans with needsRepair()
     * @param non-empty-string $collation
     *
     * @return list<string>
     */
    public function dryRunStatements(Connection $connection, string $tableName, array $plans, string $collation): array
    {
        $statements = $this->buildAlterStatements($this->mysqlPlatform($connection), $tableName, $plans, $collation);
        $table = $connection->quoteSingleIdentifier($tableName);

        $lines = [];
        if ($statements['widen'] !== null) {
            $lines[] = $statements['widen'] . ';';
        }
        foreach ($plans as $name => $plan) {
            $quoted = $connection->quoteSingleIdentifier($name);
            if ($plan->transcode) {
                $count = $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . ByteSql::needsTranscoding($quoted));
                $lines[] = '-- ' . $count . ' row(s): ' . ByteSql::transcodeUpdate($table, $quoted, (string)$plan->column->getCharset()) . ';';
            }
            if ($plan->undouble) {
                $count = $connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . ByteSql::needsUndoubling($quoted));
                $lines[] = '-- ' . $count . ' row(s), up to ' . self::MAX_UNDOUBLE_PASSES . ' passes: ' . ByteSql::undoubleUpdate($table, $quoted) . ';';
            }
        }
        $lines[] = $statements['redeclare'] . ';';

        return $lines;
    }

    /**
     * Repairs one table in place and reports whether it succeeded.
     *
     * Runs, in order: the widen DDL; with strict mode off, per column the transcode UPDATE once and
     * the undouble UPDATE until it affects no row or MAX_UNDOUBLE_PASSES is reached; then the
     * re-declare DDL. Every statement and every affected-row count is written to $output as it
     * happens. On any throwable, for example a "Duplicate entry" from a UNIQUE key, the error
     * is written, the rollback DDL restores the original declarations and false is returned;
     * rows already rewritten by a completed UPDATE keep their new bytes, which is safe because
     * every UPDATE is idempotent. Strict mode is restored in all cases.
     *
     * @param array<string, ColumnPlan> $plans keyed by column name, only plans with needsRepair()
     * @param non-empty-string $collation
     */
    public function repair(Connection $connection, OutputInterface $output, string $tableName, array $plans, string $collation): bool
    {
        $statements = $this->buildAlterStatements($this->mysqlPlatform($connection), $tableName, $plans, $collation);
        $table = $connection->quoteSingleIdentifier($tableName);

        try {
            if ($statements['widen'] !== null) {
                $output->writeln($statements['widen']);
                $connection->executeStatement($statements['widen']);
            }

            $this->runWithoutStrictMode($connection, static function () use ($output, $connection, $table, $plans): void {
                foreach ($plans as $name => $plan) {
                    $quoted = $connection->quoteSingleIdentifier($name);
                    if ($plan->transcode) {
                        $sql = ByteSql::transcodeUpdate($table, $quoted, (string)$plan->column->getCharset());
                        $output->writeln($sql);
                        $output->writeln('<info>' . $connection->executeStatement($sql) . '</info> row(s) affected.');
                    }
                    if ($plan->undouble) {
                        $sql = ByteSql::undoubleUpdate($table, $quoted);
                        $output->writeln($sql);
                        // ponytail: capped loop, one layer per pass; > 2 layers is suspicious per the runbook, so stop at 3.
                        for ($pass = 1; $pass <= self::MAX_UNDOUBLE_PASSES; $pass++) {
                            $affected = (int)$connection->executeStatement($sql);
                            $output->writeln('[pass ' . $pass . '] <info>' . $affected . '</info> row(s) affected.');
                            if ($affected === 0) {
                                break;
                            }
                        }
                    }
                }
            });

            $output->writeln($statements['redeclare']);
            $connection->executeStatement($statements['redeclare']);

            return true;
        } catch (\Throwable $exception) {
            // ponytail: no UNIQUE collision pre-check; a "Duplicate entry" lands here and the rollback keeps the table consistent.
            $output->writeln('<error>Repair of ' . $tableName . ' failed: ' . $exception->getMessage() . '</error>');
            $output->writeln('Rolling back schema: ' . $statements['rollback']);
            try {
                $connection->executeStatement($statements['rollback']);
            } catch (\Throwable $rollbackException) {
                $output->writeln('<error>Rollback of ' . $tableName . ' failed too, inspect the table manually: ' . $rollbackException->getMessage() . '</error>');
            }

            return false;
        }
    }

    /**
     * Runs $callback with STRICT_TRANS_TABLES and STRICT_ALL_TABLES removed from the session's
     * sql_mode, then restores the exact previous value, also when the callback throws.
     *
     * Needed because in strict mode a lossy CONVERT() inside an UPDATE is an error, while in a
     * SELECT it is only a warning.
     */
    private function runWithoutStrictMode(Connection $connection, callable $callback): void
    {
        $original = (string)$connection->fetchOne('SELECT @@SESSION.sql_mode');
        $stripped = implode(',', array_diff(explode(',', $original), ['', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES']));
        $connection->executeStatement('SET SESSION sql_mode = ' . $connection->quote($stripped));
        try {
            $callback();
        } finally {
            $connection->executeStatement('SET SESSION sql_mode = ' . $connection->quote($original));
        }
    }

    /** The connection's platform, narrowed to MySQL/MariaDB; throws for anything else because the DDL here is engine-specific. */
    private function mysqlPlatform(Connection $connection): AbstractMySQLPlatform
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            throw new \RuntimeException('TableRepairer needs a MySQL/MariaDB platform, got ' . $platform::class, 1758000000);
        }

        return $platform;
    }
}
