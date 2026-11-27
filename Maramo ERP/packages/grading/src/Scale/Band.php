<?php

declare(strict_types=1);

namespace Classivo\Grading\Scale;

use Classivo\Grading\InvalidConfiguration;

/**
 * One band of a grading scale: a label (A1, B2, "First Division", "Pass") covering a
 * percentage range, with an optional grade point, remark and fail flag.
 */
final class Band
{
    public function __construct(
        public readonly string $label,
        public readonly float $min,
        public readonly float $max,
        public readonly ?float $gradePoint = null,
        public readonly ?string $remark = null,
        public readonly bool $isFail = false,
    ) {
        if ($label === '') {
            throw new InvalidConfiguration('A band needs a label.');
        }
        if ($min > $max) {
            throw new InvalidConfiguration("Band [{$label}] has min {$min} above max {$max}.");
        }
        if ($min < 0 || $max > 100) {
            throw new InvalidConfiguration("Band [{$label}] must sit within 0..100.");
        }
    }

    /**
     * @param  array{label?: string, name?: string, min: float|int|string, max?: float|int|string|null, grade_point?: float|int|string|null, remark?: string|null, is_fail?: bool|int|string}  $row
     */
    public static function fromArray(array $row): self
    {
        $label = $row['label'] ?? $row['name'] ?? '';

        return new self(
            (string) $label,
            (float) $row['min'],
            isset($row['max']) ? (float) $row['max'] : 100.0,
            isset($row['grade_point']) && $row['grade_point'] !== '' ? (float) $row['grade_point'] : null,
            isset($row['remark']) && $row['remark'] !== '' ? (string) $row['remark'] : null,
            filter_var($row['is_fail'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function contains(float $percent): bool
    {
        return $percent >= $this->min && $percent <= $this->max;
    }

    /** @return array{label: string, min: float, max: float, grade_point: ?float, remark: ?string, is_fail: bool} */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'min' => $this->min,
            'max' => $this->max,
            'grade_point' => $this->gradePoint,
            'remark' => $this->remark,
            'is_fail' => $this->isFail,
        ];
    }
}
