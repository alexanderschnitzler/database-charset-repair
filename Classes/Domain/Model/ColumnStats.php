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

namespace Schnitzler\DatabaseCharsetRepair\Domain\Model;

/**
 * Byte-level counts of one column, as TableAnalyzer collects them in one aggregate SELECT.
 * They are the only input ColumnPlan::decide() looks at besides the column declaration.
 */
final readonly class ColumnStats
{
    /**
     * @param int $rows              rows in the table
     * @param int $nonascii          rows whose value holds at least one byte >= 0x80
     * @param int $invalid           rows whose value is not valid UTF-8 (genuine cp1252, runbook case 2)
     * @param int $doubled           rows carrying the double-encoding signature (runbook case 3), serialized ones included
     * @param int $doubledSerialized the subset of $doubled that is PHP serialized and therefore never rewritten
     */
    public function __construct(
        public int $rows,
        public int $nonascii,
        public int $invalid,
        public int $doubled,
        public int $doubledSerialized,
    ) {}
}
