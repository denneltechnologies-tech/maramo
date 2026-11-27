<?php

namespace Classivo\Exams\Support;

use Classivo\Exams\Models\Division;
use Classivo\Exams\Models\Grade;

/**
 * Default grading scale and divisions for the current tenant context (school, branch,
 * session). The India preset ships a CBSE-style 9-band scale; the generic preset a
 * plain A-F scale. Existing rows are never duplicated.
 */
class DefaultScales
{
    /** @return array<int, array{0: string, 1: float, 2: float, 3: float, 4: bool}> name, min, max, point, fail */
    public static function grades(?string $preset = null): array
    {
        $preset ??= (string) config('classivo.preset', 'india');

        if ($preset === 'generic') {
            return [
                ['A', 90, 100, 4.0, false],
                ['B', 80, 89.99, 3.0, false],
                ['C', 70, 79.99, 2.0, false],
                ['D', 60, 69.99, 1.0, false],
                ['F', 0, 59.99, 0.0, true],
            ];
        }

        return [
            ['A+', 90, 100, 10, false],
            ['A', 80, 89.99, 9, false],
            ['B+', 70, 79.99, 8, false],
            ['B', 60, 69.99, 7, false],
            ['C+', 50, 59.99, 6, false],
            ['C', 40, 49.99, 5, false],
            ['D', 33, 39.99, 4, false],
            ['E', 0, 32.99, 0, true],
        ];
    }

    /** @return array<string, float> name => minimum percent */
    public static function divisions(): array
    {
        return [
            'Distinction' => 75,
            'First' => 60,
            'Second' => 45,
            'Third' => 33,
            'Fail' => 0,
        ];
    }

    public static function seed(?string $preset = null): void
    {
        foreach (static::grades($preset) as [$name, $min, $max, $point, $fail]) {
            Grade::firstOrCreate(
                ['name' => $name, 'branch_id' => current_branch_id(), 'session_id' => current_session_id()],
                ['min_percent' => $min, 'max_percent' => $max, 'grade_point' => $point, 'is_fail' => $fail],
            );
        }

        foreach (static::divisions() as $name => $min) {
            Division::firstOrCreate(
                ['name' => $name, 'branch_id' => current_branch_id(), 'session_id' => current_session_id()],
                ['min_percent' => $min],
            );
        }
    }
}
