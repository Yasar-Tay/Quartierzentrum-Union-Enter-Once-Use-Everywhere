<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/**
 * One date of a series' regular pattern ("planned date", R-21), whether or not it
 * takes place. Times are in Europe/Zurich.
 */
final class Slot
{
    public const REGULAR = 'regular';
    /** Removed from the series (EXDATE), or the date is an internal booking. */
    public const SKIPPED = 'skipped';
    /** Changed on its own in time, day or room (RECURRENCE-ID). */
    public const MOVED = 'moved';
    public const CANCELLED = 'cancelled';
    /** Changed on its own, but time, day and room are the regular ones (for example a new text). */
    public const CHANGED = 'changed';

    public function __construct(
        public readonly string $originalDate,
        public readonly DateTimeImmutable $originalStart,
        public readonly DateTimeImmutable $originalEnd,
        public readonly string $status,
        public readonly ?DateTimeImmutable $actualStart,
        public readonly ?DateTimeImmutable $actualEnd,
        public readonly ?EntryFields $fields,
        /** True when the date came from a RECURRENCE-ID that is not part of the regular pattern. */
        public readonly bool $extra = false,
    ) {
    }

    public function weekday(): int
    {
        return (int) $this->originalStart->format('N');
    }

    public function takesPlace(): bool
    {
        return $this->status !== self::SKIPPED;
    }

    /** A planned date has passed when its regular end and, if moved, its actual end have passed. */
    public function passedAt(): DateTimeImmutable
    {
        if ($this->actualEnd !== null && $this->actualEnd > $this->originalEnd) {
            return $this->actualEnd;
        }

        return $this->originalEnd;
    }
}
