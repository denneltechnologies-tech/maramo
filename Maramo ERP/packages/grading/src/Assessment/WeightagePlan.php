<?php

declare(strict_types=1);

namespace Classivo\Grading\Assessment;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;

/**
 * A subject's assessment plan: weighted components summed into a total out of the sum
 * of weights, then expressed as a percentage.
 *
 * Example (CBSE-style class IX): periodic tests 10 (best 2 of 3 averaged), notebook 5,
 * subject enrichment 5, annual exam 80 => total out of 100.
 *
 * The same class combines TERMS: give each term a Component whose weight is its share
 * (e.g. term1 50, term2 50) and feed each term's percentage as Score(pct, 100).
 *
 * Component rounding (optional) is applied to each component's contribution before
 * summing — some schools round every component to whole marks, others only the total.
 */
final class WeightagePlan
{
    /** @var array<string, Component> */
    private array $components = [];

    /** @param iterable<Component> $components */
    public function __construct(
        iterable $components,
        public readonly ?Rounding $componentRounding = null,
        public readonly ?Rounding $totalRounding = null,
    ) {
        foreach ($components as $component) {
            if (isset($this->components[$component->key])) {
                throw new InvalidConfiguration("Duplicate component [{$component->key}].");
            }
            $this->components[$component->key] = $component;
        }
        if ($this->components === []) {
            throw new InvalidConfiguration('A weightage plan needs at least one component.');
        }
    }

    /** @param array{components: list<array<string, mixed>>, component_rounding?: array<string, mixed>|null, total_rounding?: array<string, mixed>|null} $config */
    public static function fromArray(array $config): self
    {
        return new self(
            array_map(static fn (array $c) => Component::fromArray($c), $config['components'] ?? []),
            isset($config['component_rounding']) ? Rounding::fromArray($config['component_rounding']) : null,
            isset($config['total_rounding']) ? Rounding::fromArray($config['total_rounding']) : null,
        );
    }

    public function maxTotal(): float
    {
        return array_sum(array_map(static fn (Component $c) => $c->weight, $this->components));
    }

    /** @return array<string, Component> */
    public function components(): array
    {
        return $this->components;
    }

    /**
     * @param  array<string, mixed>  $scores  componentKey => Score | [obtained, max] | list of those (attempts)
     */
    public function evaluate(array $scores): WeightedResult
    {
        foreach (array_keys($scores) as $key) {
            if (! isset($this->components[$key])) {
                throw new InvalidConfiguration("Score given for unknown component [{$key}].");
            }
        }

        $breakdown = [];
        $total = 0.0;
        foreach ($this->components as $key => $component) {
            if (! array_key_exists($key, $scores)) {
                throw new InvalidConfiguration("Missing scores for component [{$key}] (pass an absent Score if the student missed it).");
            }
            $value = $component->contribution(self::attempts($scores[$key]));
            if ($this->componentRounding !== null) {
                $value = $this->componentRounding->apply($value);
            }
            $breakdown[$key] = $value;
            $total += $value;
        }

        $total = round($total, 9);
        if ($this->totalRounding !== null) {
            $total = $this->totalRounding->apply($total);
        }

        return new WeightedResult($total, $this->maxTotal(), $breakdown);
    }

    /** @return list<Score> */
    private static function attempts(mixed $value): array
    {
        if ($value instanceof Score) {
            return [$value];
        }
        if (is_array($value) && $value !== [] && array_is_list($value)
            && ($value[0] instanceof Score || is_array($value[0]))) {
            return array_map(static fn ($v) => Score::from($v), $value);
        }

        return [Score::from($value)];
    }
}
