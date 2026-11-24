<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use EnterOnce\Core\SyncPlan;

/** Result of one run of one source. */
final class RunReport
{
    public const OK = 'ok';
    public const ERROR = 'error';
    public const LOCKED = 'locked';
    public const SKIPPED = 'skipped';

    /**
     * @param array<string, int> $counts
     * @param list<int> $changedPostIds
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $status,
        public readonly bool $dryRun = false,
        public readonly bool $held = false,
        public readonly array $counts = [],
        public readonly ?string $error = null,
        public readonly ?SyncPlan $plan = null,
        public readonly array $changedPostIds = [],
        /** Changes left for the next run because the time budget was used up (review D2). */
        public readonly int $remaining = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceId,
            'status' => $this->status,
            'dry_run' => $this->dryRun,
            'held' => $this->held,
            'counts' => $this->counts,
            'error' => $this->error,
            'changed' => count($this->changedPostIds),
            'remaining' => $this->remaining,
        ];
    }
}
