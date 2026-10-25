<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * Outcome of heuristic scoring: the total, which signals fired, and whether
 * the total met the configured threshold.
 */
final class ScoreResult
{
    /**
     * @param int                $total   Sum of weights of all fired signals.
     * @param array<string, int> $signals Fired signal name => points contributed.
     * @param bool               $flagged Whether $total >= the configured threshold.
     */
    public function __construct(
        public int $total,
        public array $signals,
        public bool $flagged,
    ) {
    }

    /** @return list<string> Names of the signals that fired. */
    public function firedSignals(): array
    {
        return array_keys($this->signals);
    }
}
