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

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Core\Database\Schema\Types\SetType;

/**
 * What happens to one column: nothing, a re-declaration with optional row repairs, or a refusal
 * that leaves it to a human. Decided from the byte counts alone, never from the declared
 * charset's promise, because the declared charset is exactly what cannot be trusted.
 *
 * The plan carries the column it was made for so the repairer can build DDL from the original
 * declaration. label() renders it the way README.md documents the plan column.
 */
final readonly class ColumnPlan
{
    public const TARGET_CHARSET = 'utf8mb4';
    private const UTF8_FAMILY = ['utf8', 'utf8mb3', 'utf8mb4'];
    /** Fixed-width encodings pad ASCII with NUL bytes, and NUL is valid UTF-8: their content looks like fake latin1 to every byte predicate. */
    private const FIXED_WIDTH = ['utf16', 'utf16le', 'utf32', 'ucs2'];

    /**
     * @param Column      $column             the column as introspected, source of the original declaration
     * @param bool        $enumOrSet          ENUM or SET type: re-declared in place, never widened to binary
     * @param bool        $needsRedeclaration charset or collation differs from the target
     * @param bool        $transcode          rows with invalid UTF-8 exist and will be transcoded from $sourceCharset
     * @param string      $sourceCharset      what the transcode reads the invalid rows as: the declared charset, or the assumed one for a utf8-family column
     * @param bool        $undouble           double-encoded rows reach the threshold and will lose one layer per pass
     * @param bool        $needsReview        double-encoded rows exist but stay below the threshold; reported, not rewritten
     * @param string|null $manualReason       set when the column is refused; the command exits non-zero for it
     */
    private function __construct(
        public Column $column,
        public bool $enumOrSet,
        public bool $needsRedeclaration,
        public bool $transcode,
        public string $sourceCharset,
        public bool $undouble,
        public bool $needsReview,
        public string|null $manualReason,
    ) {}

    /**
     * Classifies one column from its declaration and byte counts.
     *
     * Refuses (manual) when the column is declared in a fixed-width charset, because its bytes
     * cannot be classified; when invalid bytes sit in a column that promises UTF-8 or is an
     * ENUM/SET, because transcoding from the declared charset would be a lie there; and when an
     * ENUM/SET holds any non-ASCII value, because its declaration would have to change too.
     * $assumedCharset lifts the first of those refusals for plain columns: a utf8-family column
     * with invalid bytes is then transcoded from that charset instead, the declaration having
     * lost the name of the original one (Documentation/LegacyBytesInUtf8Columns.md). Otherwise:
     * invalid bytes mean transcoding from the declared charset; double-encoded rows mean undoubling when their
     * share of the non-ASCII rows is at least $threshold, and a review note when it is below.
     * A charset or collation that differs from utf8mb4 / $collation needs re-declaration even
     * with clean bytes.
     */
    public static function decide(Column $column, ColumnStats $stats, float $threshold, string $collation, string|null $assumedCharset = null): self
    {
        $enumOrSet = in_array(Type::lookupName($column->getType()), [Types::ENUM, SetType::TYPE], true);
        $declared = (string)$column->getCharset();
        $utf8Family = in_array($declared, self::UTF8_FAMILY, true);
        $needsRedeclaration = $declared !== self::TARGET_CHARSET || $column->getCollation() !== $collation;
        $sourceCharset = $utf8Family && !$enumOrSet && $assumedCharset !== null ? $assumedCharset : $declared;

        if (in_array($declared, self::FIXED_WIDTH, true)) {
            return new self($column, $enumOrSet, $needsRedeclaration, false, $declared, false, false, 'fixed-width ' . $declared . ' column, bytes cannot be classified');
        }
        if ($stats->invalid > 0 && ($enumOrSet || $sourceCharset === $declared && $utf8Family)) {
            $hint = $utf8Family && !$enumOrSet ? ', see --assume' : '';

            return new self($column, $enumOrSet, $needsRedeclaration, false, $declared, false, false, 'invalid bytes in a ' . $declared . ' column' . $hint);
        }
        if ($enumOrSet && $stats->nonascii > 0) {
            return new self($column, $enumOrSet, $needsRedeclaration, false, $declared, false, false, 'non-ASCII enum/set');
        }

        $ratio = $stats->nonascii > 0 ? $stats->doubled / $stats->nonascii : 0.0;
        $undouble = $stats->doubled > 0 && $ratio >= $threshold;

        return new self($column, $enumOrSet, $needsRedeclaration, $stats->invalid > 0, $sourceCharset, $undouble, $stats->doubled > 0 && !$undouble, null);
    }

    /** The transcode reads from an assumed charset because the declaration (utf8 family) could not name the original one. */
    public function transcodesFromAssumedCharset(): bool
    {
        return $this->transcode && $this->sourceCharset !== (string)$this->column->getCharset();
    }

    /** The column was refused and is left to a human; see $manualReason for why. */
    public function isManual(): bool
    {
        return $this->manualReason !== null;
    }

    /** At least one statement will run for this column: a re-declaration, a transcoding or an undoubling. Never true for a refused column. */
    public function needsRepair(): bool
    {
        return !$this->isManual() && ($this->needsRedeclaration || $this->transcode || $this->undouble);
    }

    /**
     * The plan as printed in the analysis table: `ok`, `redeclare [+transcode [(assumed <charset>)]] [+undouble]`
     * or `manual (<reason>)`, with ` (review)` appended when double-encoded rows stay below the
     * threshold.
     */
    public function label(): string
    {
        if ($this->manualReason !== null) {
            return 'manual (' . $this->manualReason . ')';
        }
        $label = $this->needsRepair()
            ? 'redeclare' . ($this->transcode ? ' +transcode' . ($this->transcodesFromAssumedCharset() ? ' (assumed ' . $this->sourceCharset . ')' : '') : '') . ($this->undouble ? ' +undouble' : '')
            : 'ok';

        return $label . ($this->needsReview ? ' (review)' : '');
    }
}
