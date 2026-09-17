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

namespace Schnitzler\DatabaseCharsetRepair\Application\Command;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Schnitzler\DatabaseCharsetRepair\Domain\Model\ColumnPlan;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableAnalyzer;
use Schnitzler\DatabaseCharsetRepair\Infrastructure\Database\TableRepairer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Analyzes and, with --fix, repairs the three charset failure modes described in
 * Documentation/Runbook.md: fake latin1, genuine cp1252 and double encoding.
 * Decides per column, never transcodes blindly, is idempotent and dry by default.
 *
 * Glue only: options in, tables out. The per-column decision lives in Domain\Model\ColumnPlan,
 * the byte predicates and the SQL execution in Infrastructure\Database.
 */
#[AsCommand('schnitzler:database-charset-repair:utf8mb4')]
final class Utf8mb4Command extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TableAnalyzer $analyzer,
        private readonly TableRepairer $repairer,
    ) {
        parent::__construct();
    }

    /** Declares the options README.md documents; defaults live here and nowhere else. */
    protected function configure(): void
    {
        $this->addOption('fix', null, InputOption::VALUE_NONE, 'Apply the repairs. Without it only analyze and print the SQL that would run.');
        $this->addOption('collation', null, InputOption::VALUE_REQUIRED, 'Target collation', 'utf8mb4_unicode_ci');
        $this->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Share of non-ASCII rows that must carry the double-encoding signature before a column is undoubled; below it the column is only reported for review', '0.9');
        $this->addOption('report', null, InputOption::VALUE_REQUIRED, 'CSV path: table,column,uid,before,after for every double-encoded row, threshold or not');
        $this->addOption('table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to this table, repeatable');
        $this->addOption('column', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to this column, as table.column, repeatable; implies --table for that table');
    }

    /**
     * Analyzes every table in scope, prints one plan table per database table, then either
     * prints the SQL a repair would run (default) or runs it (--fix) and finishes with ALTER
     * DATABASE. Fails early on a non-MySQL platform, a missing database or an empty --collation.
     * Writes the CSV report when --report is given. Returns FAILURE when any column was refused
     * (manual) or any table repair failed, SUCCESS otherwise; a dry run with nothing to do is
     * SUCCESS too.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $style->error('This command relies on MySQL/MariaDB specific SQL (CONVERT ... USING, information_schema, ALTER TABLE ... MODIFY) and does not support ' . $connection->getDatabasePlatform()::class . '.');

            return Command::FAILURE;
        }
        $database = $connection->getDatabase();
        if ($database === null) {
            $style->error('The connection has no database selected.');

            return Command::FAILURE;
        }

        $fix = (bool)$input->getOption('fix');
        $collation = (string)$input->getOption('collation');
        if ($collation === '') {
            $style->error('--collation must not be empty.');

            return Command::FAILURE;
        }
        $threshold = (float)$input->getOption('threshold');
        $reportPath = $input->getOption('report');
        $tableFilter = $input->getOption('table');
        $columnFilter = [];
        foreach ($input->getOption('column') as $spec) {
            if (substr_count($spec, '.') !== 1) {
                $style->error('--column expects table.column, got "' . $spec . '".');

                return Command::FAILURE;
            }
            [$table, $column] = explode('.', $spec);
            $columnFilter[$table][] = $column;
        }
        if ($tableFilter === [] && $columnFilter !== []) {
            $tableFilter = array_keys($columnFilter);
        }

        $this->printConnectionInfo($style, $connection);

        $schemaManager = $connection->createSchemaManager();
        $tableNames = $tableFilter !== []
            ? $tableFilter
            : array_map(static fn(OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(), $schemaManager->introspectTableNames());

        $hasManual = false;
        $tablesToFix = [];
        $reportRows = [];

        foreach ($tableNames as $tableName) {
            if (str_starts_with($tableName, 'zzz')) {
                continue;
            }

            $table = $schemaManager->introspectTableByUnquotedName($tableName);
            ['columns' => $columns, 'skipped' => $skipped] = $this->analyzer->columnsInScope($table);
            if (isset($columnFilter[$tableName])) {
                $missing = array_diff($columnFilter[$tableName], array_keys($columns));
                if ($missing !== []) {
                    $style->error('Not in scope of ' . $tableName . ' (unknown, no charset, or ascii): ' . implode(', ', $missing));

                    return Command::FAILURE;
                }
                $columns = array_intersect_key($columns, array_flip($columnFilter[$tableName]));
                $skipped = [];
            }
            if ($columns === []) {
                continue;
            }

            $stats = $this->analyzer->collectStats($connection, $tableName, $columns);

            $style->section($tableName);
            if ($skipped !== []) {
                $style->writeln('<comment>skipped (deliberate charset): ' . implode(', ', $skipped) . '</comment>');
            }

            $rows = [];
            $work = [];
            $reviewColumns = [];
            foreach ($columns as $name => $column) {
                $s = $stats[$name];
                $plan = ColumnPlan::decide($column, $s, $threshold, $collation);
                $hasManual = $hasManual || $plan->isManual();
                if ($plan->needsRepair()) {
                    $work[$name] = $plan;
                }
                if ($plan->needsReview) {
                    $reviewColumns[] = $name;
                }

                $rows[] = [$name, $column->getCharset() . '/' . $column->getCollation(), $s->rows, $s->nonascii, $s->invalid, $s->doubled, $s->doubledSerialized, $plan->label()];

                if ($reportPath !== null && $s->doubled > 0) {
                    foreach ($this->analyzer->doubleEncodedRows($connection, $tableName, $name, $table->hasColumn('uid')) as $row) {
                        $reportRows[] = [$tableName, $name, $row['uid'], $row['before'], $row['after']];
                    }
                }
            }

            $style->table(['column', 'charset/collation', 'rows', 'nonascii', 'invalid', 'doubled', 'doubled+serialized', 'plan'], $rows);

            foreach ($reviewColumns as $name) {
                $style->writeln('<comment>review samples for ' . $name . ' (below threshold, not undoubled):</comment>');
                foreach ($this->analyzer->doubleEncodedRows($connection, $tableName, $name, $table->hasColumn('uid'), limit: 5, truncate: 80) as $sample) {
                    $style->writeln('  ' . json_encode($sample, JSON_UNESCAPED_UNICODE));
                }
            }

            if ($work !== []) {
                $tablesToFix[$tableName] = $work;
            }
        }

        $alterDatabase = sprintf(
            'ALTER DATABASE %s CHARACTER SET = %s COLLATE = %s',
            $connection->quoteSingleIdentifier($database),
            ColumnPlan::TARGET_CHARSET,
            $collation,
        );

        $failed = false;
        if ($tablesToFix === []) {
            $style->writeln('<info>Nothing to do.</info>');
        } elseif ($fix) {
            foreach ($tablesToFix as $tableName => $work) {
                $style->section('Repair ' . $tableName);
                $failed = !$this->repairer->repair($connection, $style, $tableName, $work, $collation) || $failed;
            }
            $style->section('ALTER DATABASE');
            $style->writeln($alterDatabase);
            $connection->executeStatement($alterDatabase);
        } else {
            $style->section('Dry run');
            foreach ($tablesToFix as $tableName => $work) {
                $style->writeln($this->repairer->dryRunStatements($connection, $tableName, $work, $collation));
            }
            $style->writeln($alterDatabase . ';');
        }

        if ($reportPath !== null) {
            $this->writeReport((string)$reportPath, $reportRows);
            $style->writeln(sprintf('<info>%d</info> double-encoded row(s) written to %s', count($reportRows), $reportPath));
        }

        return $hasManual || $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Prints host, user, database, server version, the server's charset and collation and the
     * charset TYPO3 uses for the connection, then warns when the latter is not utf8mb4: that
     * setting is what a migrated database is read through, and this command does not edit it.
     */
    private function printConnectionInfo(SymfonyStyle $style, Connection $connection): void
    {
        $style->section('Connection');
        $connectionCharset = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['charset'] ?? '(unset)';
        $style->table(
            ['host', 'user', 'database', 'server', '@@character_set_server', '@@collation_server', 'TYPO3 connection charset'],
            [[
                $connection->getParams()['host'] ?? '',
                $connection->getParams()['user'] ?? '',
                $connection->getDatabase(),
                $connection->getServerVersion(),
                (string)$connection->fetchOne('SELECT @@character_set_server'),
                (string)$connection->fetchOne('SELECT @@collation_server'),
                $connectionCharset,
            ]],
        );
        if ($connectionCharset !== ColumnPlan::TARGET_CHARSET) {
            $style->warning(sprintf(
                'DB.Connections.Default.charset is "%s", not "%s". Align config/system/settings.php (charset and tableoptions) after the migration; this command does not edit it.',
                $connectionCharset,
                ColumnPlan::TARGET_CHARSET,
            ));
        }
    }

    /**
     * Writes the double-encoded rows as CSV with the header table,column,uid,before,after to
     * $path, replacing any existing file. Throws when the file cannot be written.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    private function writeReport(string $path, array $rows): void
    {
        $buffer = fopen('php://memory', 'r+');
        if ($buffer === false) {
            throw new \RuntimeException('Cannot open an in-memory stream for the report.');
        }
        fputcsv($buffer, ['table', 'column', 'uid', 'before', 'after']);
        foreach ($rows as $row) {
            fputcsv($buffer, $row);
        }
        rewind($buffer);
        if (!GeneralUtility::writeFile($path, (string)stream_get_contents($buffer))) {
            throw new \RuntimeException('Cannot write report to ' . $path);
        }
    }
}
