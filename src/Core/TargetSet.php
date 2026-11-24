<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/** Output of {@see Core::targets()}: the site-side target records of one source. */
final class TargetSet
{
    /**
     * @param list<TargetRecord> $targets records that should exist on the site (status publish or draft)
     * @param list<TargetRecord> $pending weekly offers whose first date is more than `weekly_lead_days` away (R-26); they do not exist on the site yet
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly DateTimeImmutable $now,
        public readonly Window $window,
        public readonly array $targets,
        public readonly array $pending,
    ) {
    }

    /** @return list<TargetRecord> */
    public function ofKind(string $kind, ?string $status = null): array
    {
        return array_values(array_filter(
            $this->targets,
            static fn (TargetRecord $t) => $t->kind === $kind && ($status === null || $t->status === $status)
        ));
    }

    public function byKey(string $key): ?TargetRecord
    {
        foreach ($this->targets as $t) {
            if ($t->key === $key) {
                return $t;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $count = fn (string $kind, ?string $status, ?bool $past = null) => count(array_filter(
            $this->targets,
            static fn (TargetRecord $t) => $t->kind === $kind && ($status === null || $t->status === $status) && ($past === null || $t->past === $past)
        ));

        return [
            'weekly_offers' => $count(TargetRecord::WEEKLY_OFFER, TargetRecord::PUBLISH),
            'dated_events' => $count(TargetRecord::DATED_EVENT, TargetRecord::PUBLISH),
            'weekly_offers_draft' => $count(TargetRecord::WEEKLY_OFFER, TargetRecord::DRAFT),
            'dated_events_draft' => $count(TargetRecord::DATED_EVENT, TargetRecord::DRAFT),
            'past' => count(array_filter($this->targets, static fn (TargetRecord $t) => $t->past)),
            'pending_weekly_offers' => count($this->pending),
        ];
    }
}
