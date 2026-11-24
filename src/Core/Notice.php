<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * A notice about one event or series (plan section 4.5). Counted once per event
 * or series, never per date; raised only for entries that pass screening and are
 * not cancelled.
 */
final class Notice
{
    public const WARNING = 'warning';
    public const INFO = 'info';

    /** An unknown category or tag was corrected (synonym table excluded, see below). */
    public const CODE_VALUE_CORRECTED = 'value_corrected';
    /** An unknown category or tag was dropped. */
    public const CODE_VALUE_DROPPED = 'value_dropped';
    /** A series has two dates on one day; the series is not synced. */
    public const CODE_TWO_DATES_ONE_DAY = 'two_dates_one_day';
    /** The event has no category after mapping. */
    public const CODE_NO_CATEGORY = 'no_category';

    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $sourceId,
        public readonly string $uid,
        public readonly string $title,
        /** German text for the digest and the status page. */
        public readonly string $message,
        /** The value the notice is about (the unknown value, or the day), or null. */
        public readonly ?string $value = null,
        /** For value_corrected: the value it was corrected to. */
        public readonly ?string $correctedTo = null,
        /** 'category' or 'tag' for value notices, else null. */
        public readonly ?string $field = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'source_id' => $this->sourceId,
            'uid' => $this->uid,
            'title' => $this->title,
            'field' => $this->field,
            'value' => $this->value,
            'corrected_to' => $this->correctedTo,
            'message' => $this->message,
        ];
    }

    public function dedupeKey(): string
    {
        return implode("\x1F", [$this->level, $this->code, $this->uid, (string) $this->field, (string) $this->value]);
    }
}
