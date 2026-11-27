<?php

namespace Classivo\Fees\Support;

use Classivo\Fees\Models\FeeClearanceExemption;

/**
 * The answer {@see FeeClearancePolicy} gives about one student and one service:
 * released or held, the figures behind it, and — when it is held — the sentence
 * to show the person who asked.
 *
 * It is a value object rather than a bare boolean because a refusal that says
 * only "no" is the thing this feature must not become. The caller needs the
 * message, and an auditor later needs to know it was an EXEMPTION that released a
 * document rather than a payment.
 */
final class FeeClearance
{
    private function __construct(
        public readonly bool $held,
        public readonly string $service,
        public readonly float $outstanding,
        public readonly int $daysOverdue,
        public readonly string $message,
        public readonly ?FeeClearanceExemption $exemption = null,
    ) {}

    /** Nothing to withhold: the gate is off, the debt is under threshold, or it is paid. */
    public static function released(string $service, float $outstanding = 0.0, int $daysOverdue = 0): self
    {
        return new self(false, $service, $outstanding, $daysOverdue, '');
    }

    /**
     * Over threshold, but an approved hardship exemption covers it. Released, and
     * the exemption is carried so the decision can be explained afterwards.
     */
    public static function exempted(string $service, float $outstanding, int $daysOverdue, FeeClearanceExemption $exemption): self
    {
        return new self(false, $service, $outstanding, $daysOverdue, '', $exemption);
    }

    public static function held(string $service, float $outstanding, int $daysOverdue, string $message): self
    {
        return new self(true, $service, $outstanding, $daysOverdue, $message);
    }

    public function isReleased(): bool
    {
        return ! $this->held;
    }

    /** True when the release came from the override list rather than from payment. */
    public function wasExempted(): bool
    {
        return $this->exemption !== null;
    }
}
