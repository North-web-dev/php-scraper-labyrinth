<?php

declare(strict_types=1);

namespace NorthWebDev\Labyrinth;

/**
 * Per-request context the scorer cannot derive from headers alone.
 *
 * These are computed by {@see Labyrinth} (from the resolver and the store) and
 * handed to {@see HeuristicScorer} so the scorer stays pure and unit-testable.
 */
final class HeuristicContext
{
    /**
     * @param bool|null $hasPtr                Whether the client IP has a PTR record. null = unknown
     *                                         (e.g. lookup skipped); unknown never contributes points.
     * @param bool      $hasPriorAssetRequests Whether this IP has previously fetched any static asset.
     */
    public function __construct(
        public ?bool $hasPtr = null,
        public bool $hasPriorAssetRequests = true,
    ) {
    }
}
