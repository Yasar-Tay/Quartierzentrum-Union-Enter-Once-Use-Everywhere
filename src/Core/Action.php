<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * One action of a sync plan.
 *
 * Types:
 *  - create:    write a new managed record from `target` (status publish).
 *  - update:    write `target` onto the existing record `site_key` (status from `status`);
 *               when `site_key` differs from `key` (R-27 reuse) the shell also rewrites `_enteronce_key`.
 *  - unpublish: set the record to draft (R-06, never delete).
 *  - keep:      write nothing except `missing_runs` when it differs from the stored value.
 *  - hold:      an unpublish withheld by R-13; shown on the status page, applied only after
 *               «Trotzdem anwenden» (diff again with releaseHeld = true).
 */
final class Action
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const UNPUBLISH = 'unpublish';
    public const KEEP = 'keep';
    public const HOLD = 'hold';

    /**
     * @param array<string, array{0: bool, 1: bool}> $flagChanges flag => [before, after], when known
     */
    public function __construct(
        public readonly string $type,
        public readonly string $kind,
        /** Sync key the record has after the action. */
        public readonly string $key,
        public readonly string $reason,
        /** Post status after the action: publish or draft. */
        public readonly string $status,
        /** `_enteronce_missing_runs` to store after the action. */
        public readonly int $missingRuns,
        public readonly ?TargetRecord $target = null,
        /** Key of the existing site record this action applies to (null for create). */
        public readonly ?string $siteKey = null,
        /** The shell's id of the existing record, passed through from SiteRecord::$id. */
        public readonly int|string|null $siteId = null,
        /** `_enteronce_hash` to store after the action (null: leave as is). */
        public readonly ?string $hash = null,
        public readonly array $flagChanges = [],
    ) {
    }

    public function isChange(): bool
    {
        return $this->type !== self::KEEP;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'kind' => $this->kind,
            'key' => $this->key,
            'reason' => $this->reason,
            'status' => $this->status,
            'missing_runs' => $this->missingRuns,
            'site_key' => $this->siteKey,
            'site_id' => $this->siteId,
            'hash' => $this->hash,
            'flag_changes' => $this->flagChanges === [] ? (object) [] : $this->flagChanges,
            'target' => $this->target?->toArray(),
        ];
    }
}
