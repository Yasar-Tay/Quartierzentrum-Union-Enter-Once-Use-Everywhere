<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The sync window: a half-open interval [start, end) in Europe/Zurich.
 *
 * An occurrence is inside the window when it overlaps it (start < window end
 * and end > window start).
 */
final class Window
{
    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        $tz = Settings::timezone();
        $start = $start->setTimezone($tz);
        $end = $end->setTimezone($tz);
        if ($end <= $start) {
            throw new InvalidArgumentException('Window end must be after its start.');
        }
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Window from the first to the last day, both inclusive, as local dates:
     * `Window::fromDates('2026-11-19', '2027-03-31')` covers 19 Nov 00:00 to 1 Apr 00:00.
     */
    public static function fromDates(string $firstDay, string $lastDay): self
    {
        $tz = Settings::timezone();
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $firstDay, $tz);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $lastDay, $tz);
        if ($start === false || $last === false) {
            throw new InvalidArgumentException('Dates must be YYYY-MM-DD.');
        }

        return new self($start, $last->modify('+1 day'));
    }

    /**
     * The standard window for a run: from the start of today to the end of the day
     * `$monthsAhead` months later (`window_months_ahead`, A-11).
     */
    public static function monthsAhead(DateTimeImmutable $now, int $monthsAhead): self
    {
        $local = $now->setTimezone(Settings::timezone());
        $start = $local->setTime(0, 0);
        $end = $start->modify('+' . max(0, $monthsAhead) . ' months')->modify('+1 day');

        return new self($start, $end);
    }

    public function overlaps(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return $start < $this->end && $end > $this->start;
    }

    /** @return array{start: string, end: string} */
    public function toArray(): array
    {
        return ['start' => $this->start->format(DATE_ATOM), 'end' => $this->end->format(DATE_ATOM)];
    }
}
