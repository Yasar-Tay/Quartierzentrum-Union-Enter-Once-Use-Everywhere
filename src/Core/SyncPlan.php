<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/** Output of {@see Core::diff()}: the actions of one run of one source. */
final class SyncPlan
{
    /**
     * @param list<Action> $actions one per target record and per managed site record of the source
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $actions,
        /** R-11 / R-31: the shell MUST NOT apply anything (not even missing_runs). */
        public readonly bool $dryRun,
        /** R-13 triggered: the unpublish actions were turned into hold actions. */
        public readonly bool $held,
    ) {
    }

    /** @return list<Action> */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->actions, static fn (Action $a) => $a->type === $type));
    }

    /** Actions that change something on the site (everything but keep). @return list<Action> */
    public function changes(): array
    {
        return array_values(array_filter($this->actions, static fn (Action $a) => $a->isChange()));
    }

    public function hasChanges(): bool
    {
        return $this->changes() !== [];
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [Action::CREATE => 0, Action::UPDATE => 0, Action::UNPUBLISH => 0, Action::KEEP => 0, Action::HOLD => 0];
        foreach ($this->actions as $a) {
            ++$counts[$a->type];
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'dry_run' => $this->dryRun,
            'held' => $this->held,
            'counts' => $this->counts(),
            'actions' => array_map(static fn (Action $a) => $a->toArray(), $this->actions),
        ];
    }
}
