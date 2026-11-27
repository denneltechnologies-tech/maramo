<?php

declare(strict_types=1);

namespace Classivo\Grading\Pass;

/**
 * Evaluates every subject of one student and, within a GracePolicy, adds the fewest
 * grace marks needed to pass failing subjects. Subjects closest to passing are served
 * first so the limited grace clears as many subjects as possible. Grace is only given
 * where it fully clears a subject — partial grace never changes a result.
 */
final class GraceAllocator
{
    public function __construct(
        private readonly PassRule $defaultRule,
        private readonly GracePolicy $policy = new GracePolicy,
    ) {}

    /**
     * @param  array<string, SubjectMarks>  $subjects
     * @param  array<string, PassRule>  $rules  per-subject override of the default rule
     */
    public function evaluate(array $subjects, array $rules = []): StudentOutcome
    {
        $outcomes = [];
        foreach ($subjects as $key => $marks) {
            $outcomes[$key] = ($rules[$key] ?? $this->defaultRule)->evaluate($marks);
        }

        $failing = array_filter($outcomes, static fn (SubjectOutcome $o) => ! $o->passed);
        uasort($failing, static fn (SubjectOutcome $a, SubjectOutcome $b) => $a->shortfall() <=> $b->shortfall());

        $remaining = $this->policy->maxTotal;
        $subjectsLeft = $this->policy->maxSubjects;
        $granted = [];

        foreach ($failing as $key => $outcome) {
            $need = round($outcome->shortfall(), 9);
            if ($subjectsLeft < 1 || $need <= 0 || $need > $this->policy->capFor($outcome->marks->max()) || $need > $remaining + 1e-9) {
                continue;
            }
            $granted[$key] = $outcome;
            $remaining -= $need;
            $subjectsLeft--;
        }

        if ($this->policy->allOrNothing && count($granted) !== count($failing)) {
            $granted = [];
        }

        foreach ($granted as $key => $outcome) {
            $patched = $outcome->marks;
            foreach ($outcome->shortfalls as $part => $need) {
                $patched = $part === 'overall'
                    ? self::spread($patched, $need)
                    : $patched->withAdded($part, $need);
            }
            $rule = $rules[$key] ?? $this->defaultRule;
            $re = $rule->evaluate($patched);
            $outcomes[$key] = new SubjectOutcome($patched, $re->passed, $re->failedParts, $re->shortfalls, $outcome->shortfall());
        }

        return new StudentOutcome($outcomes);
    }

    /** Overall grace fills parts in their listed order, up to each part's maximum. */
    private static function spread(SubjectMarks $marks, float $need): SubjectMarks
    {
        foreach ($marks->parts as $name => $score) {
            if ($need <= 0) {
                break;
            }
            $room = $score->max - ($score->obtained ?? 0.0);
            if ($room <= 0) {
                continue;
            }
            $add = min($room, $need);
            $marks = $marks->withAdded($name, $add);
            $need -= $add;
        }

        return $marks;
    }
}
