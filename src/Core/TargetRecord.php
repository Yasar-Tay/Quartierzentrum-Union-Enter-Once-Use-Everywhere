<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/**
 * What one managed record on the association site should look like after this run.
 *
 * `fields` holds exactly the values the WordPress adapter writes (after mapping);
 * the content hash is computed from them. Everything else is metadata for the diff.
 */
final class TargetRecord
{
    public const DATED_EVENT = 'dated_event';
    public const WEEKLY_OFFER = 'weekly_offer';

    public const PUBLISH = 'publish';
    public const DRAFT = 'draft';

    public readonly string $hash;
    public readonly string $hashWithoutText;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $sourceId,
        public readonly string $uid,
        public readonly ?string $seriesUid,
        /** Desired post status: publish, or draft for cancelled (cancel_mode unpublish) and ended weekly offers. */
        public readonly string $status,
        /** Why the status is draft: 'cancelled' or 'series_ended'; null when publish. */
        public readonly ?string $statusReason,
        /** R-07: dated event whose end has passed / weekly offer whose last date has passed. */
        public readonly bool $past,
        /** Span the record covers: event start, or first date of a weekly offer. */
        public readonly DateTimeImmutable $start,
        /** Event end, or end of the last date of a weekly offer; null for an open-ended series. */
        public readonly ?DateTimeImmutable $end,
        public readonly array $fields,
        /** Weekly offers: the next planned date (R-21), not hashed. */
        public readonly ?string $nextDate = null,
    ) {
        $this->hash = ContentHash::of($fields);
        $this->hashWithoutText = ContentHash::of($fields, true);
    }

    /** The hash to compare with and store on a site record, depending on `_enteronce_keep_text`. */
    public function hashFor(bool $keepText): string
    {
        return $keepText ? $this->hashWithoutText : $this->hash;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'source_id' => $this->sourceId,
            'uid' => $this->uid,
            'series_uid' => $this->seriesUid,
            'status' => $this->status,
            'status_reason' => $this->statusReason,
            'past' => $this->past,
            'start' => $this->start->format(DATE_ATOM),
            'end' => $this->end?->format(DATE_ATOM),
            'next_date' => $this->nextDate,
            'fields' => $this->fields,
            'hash' => $this->hash,
            'hash_without_text' => $this->hashWithoutText,
        ];
    }
}
