<?php

declare(strict_types=1);

namespace Classivo\Grading\Adapters;

use ArrayAccess;
use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;
use Classivo\Grading\Scale\Band;
use Classivo\Grading\Scale\GradeScale;
use Classivo\Grading\Schemes\PercentageScheme;

/**
 * Maps rows of the Classivo `grades` table (columns name, min_percent, max_percent,
 * grade_point, remark, is_fail — one row per band, per school/branch/session) to a
 * scheme. Rows may be arrays, ArrayAccess objects or plain/Eloquent objects with those
 * attributes; the adapter has no Laravel dependency. Soft-deleted rows should be
 * excluded by the caller's query (Eloquent does that by default).
 *
 * Gap policy defaults to STRICT, matching the original Grade::forPercentage()
 * (percentage must fall inside [min_percent, max_percent]); pass THRESHOLD to close
 * gaps between whole-number bands.
 */
final class GradeTableAdapter
{
    /** @param iterable<mixed> $rows */
    public static function toScale(iterable $rows, string $gapPolicy = GradeScale::STRICT): GradeScale
    {
        $bands = [];
        foreach ($rows as $row) {
            $bands[] = self::toBand($row);
        }

        return new GradeScale($bands, $gapPolicy);
    }

    /**
     * @param  iterable<mixed>  $rows
     * @param  float|null  $passPercentage  null = lowest min_percent among non-fail rows (0 if all fail)
     */
    public static function toScheme(
        iterable $rows,
        string $key = 'school.custom',
        ?string $name = null,
        ?float $passPercentage = null,
        ?Rounding $rounding = null,
        string $gapPolicy = GradeScale::STRICT,
    ): PercentageScheme {
        $scale = self::toScale($rows, $gapPolicy);

        return new PercentageScheme(
            $key,
            $name ?? 'School grading scale',
            $scale,
            $passPercentage ?? $scale->lowestPassingMin() ?? 0.0,
            $rounding ?? Rounding::none(),
        );
    }

    public static function toBand(mixed $row): Band
    {
        $name = self::read($row, 'name');
        if ($name === null || $name === '') {
            throw new InvalidConfiguration('Grade row has no name.');
        }
        $gp = self::read($row, 'grade_point');
        $remark = self::read($row, 'remark');

        return new Band(
            (string) $name,
            (float) (self::read($row, 'min_percent') ?? 0),
            (float) (self::read($row, 'max_percent') ?? 100),
            $gp === null || $gp === '' ? null : (float) $gp,
            $remark === null || $remark === '' ? null : (string) $remark,
            filter_var(self::read($row, 'is_fail') ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /** Inverse mapping, e.g. to seed the grades table from a preset. @return list<array<string, mixed>> */
    public static function toRows(GradeScale $scale): array
    {
        return array_map(static fn (Band $b) => [
            'name' => $b->label,
            'min_percent' => $b->min,
            'max_percent' => $b->max,
            'grade_point' => $b->gradePoint,
            'remark' => $b->remark,
            'is_fail' => $b->isFail,
        ], $scale->bands());
    }

    private static function read(mixed $row, string $field): mixed
    {
        if (is_array($row)) {
            return $row[$field] ?? null;
        }
        if ($row instanceof ArrayAccess) {
            return $row[$field] ?? null;
        }
        if (is_object($row)) {
            return $row->{$field} ?? null;
        }

        throw new InvalidConfiguration('Grade rows must be arrays or objects.');
    }
}
