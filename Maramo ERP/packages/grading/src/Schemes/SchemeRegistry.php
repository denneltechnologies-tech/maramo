<?php

declare(strict_types=1);

namespace Classivo\Grading\Schemes;

use Classivo\Grading\InvalidConfiguration;

/** A keyed collection of schemes with a default. */
final class SchemeRegistry
{
    /** @var array<string, GradingScheme> */
    private array $schemes = [];

    private ?string $default = null;

    /** @param iterable<GradingScheme> $schemes */
    public function __construct(iterable $schemes = [], ?string $default = null)
    {
        foreach ($schemes as $scheme) {
            $this->register($scheme);
        }
        if ($default !== null) {
            $this->setDefault($default);
        }
    }

    /** Registering an existing key replaces it (lets apps override a preset). */
    public function register(GradingScheme $scheme): self
    {
        $this->schemes[$scheme->key()] = $scheme;
        $this->default ??= $scheme->key();

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->schemes[$key]);
    }

    public function get(?string $key = null): GradingScheme
    {
        $key ??= $this->default;
        if ($key === null || ! isset($this->schemes[$key])) {
            throw new InvalidConfiguration('Unknown grading scheme ['.($key ?? 'null').']. Known: '.implode(', ', $this->keys()).'.');
        }

        return $this->schemes[$key];
    }

    public function setDefault(string $key): self
    {
        if (! isset($this->schemes[$key])) {
            throw new InvalidConfiguration("Cannot make unknown scheme [{$key}] the default.");
        }
        $this->default = $key;

        return $this;
    }

    public function defaultKey(): ?string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->schemes);
    }

    /** @return array<string, GradingScheme> */
    public function all(): array
    {
        return $this->schemes;
    }
}
