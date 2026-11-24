<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/**
 * Turns a {@see CalendarResult} into site-side target records: weekly offers
 * (R-20 to R-26) and dated events (everything else, including R-25).
 */
final class TargetBuilder
{
    private readonly Mapper $mapper;

    public function __construct(private readonly Settings $settings)
    {
        $this->mapper = new Mapper($settings);
    }

    public function build(CalendarResult $result): TargetSet
    {
        $now = $result->now;
        $targets = [];
        $pending = [];
        /** @var array<string, true> $weeklyHandled "uid|weekday" whose dates are not dated events */
        $weeklyHandled = [];

        if ($this->settings->weeklyMode === 'weekly_offer') {
            foreach ($result->series as $series) {
                if ($series->conflict || !$series->weekly) {
                    continue;
                }
                foreach ($series->weekdays as $weekday) {
                    $dated = $series->datedSlots($weekday);
                    if ($dated === []) {
                        continue;
                    }
                    // R-20: at least weekly_min_dates dates on this weekday (an open-ended series always has).
                    if (!$series->openEnded && count($dated) < $this->settings->weeklyMinDates) {
                        continue;
                    }
                    $weeklyHandled[$series->uid . '|' . $weekday] = true;
                    $target = $this->weeklyOffer($series, $weekday, $dated, $now);
                    // R-26: published when the first date is at most weekly_lead_days away.
                    $leadLimit = $now->modify('+' . $this->settings->weeklyLeadDays . ' days');
                    if ($target->start > $leadLimit) {
                        $pending[] = $target;
                    } else {
                        $targets[] = $target;
                    }
                }
            }
        }

        foreach ($result->occurrences as $occurrence) {
            if ($occurrence->seriesUid !== null && $occurrence->originalDate !== null) {
                $weekday = (int) (new DateTimeImmutable($occurrence->originalDate, Settings::timezone()))->format('N');
                if (isset($weeklyHandled[$occurrence->seriesUid . '|' . $weekday])) {
                    continue;
                }
            }
            $targets[] = $this->datedEvent($occurrence, $now);
        }

        return new TargetSet($this->settings->sourceId, $now, $result->window, $targets, $pending);
    }

    /** @param list<Slot> $dated */
    private function weeklyOffer(SeriesDefinition $series, int $weekday, array $dated, DateTimeImmutable $now): TargetRecord
    {
        $f = $series->fields;
        $first = $dated[0];
        $last = $dated[count($dated) - 1];
        $ended = !$series->openEnded && $last->passedAt() <= $now;

        // R-21, R-22, R-24: the next date of the regular pattern after now, whether or not it takes place.
        $next = null;
        if (!$ended) {
            foreach ($series->slots as $slot) {
                if ($slot->weekday() === $weekday && !$slot->extra && $slot->passedAt() > $now) {
                    $next = $slot;
                    break;
                }
            }
        }
        $ferienpause = $next !== null && $next->status === Slot::SKIPPED;
        $abgesagt = ($next !== null && $next->status === Slot::CANCELLED)
            || ($f->cancelled && $this->settings->cancelMode === 'badge');
        $note = null;
        if ($this->settings->movedDateNote && $next !== null && $next->status === Slot::MOVED) {
            $note = MovedDateNote::text($next, $f->room, $series->allDay);
        }

        $status = TargetRecord::PUBLISH;
        $reason = null;
        if ($ended) {
            $status = TargetRecord::DRAFT;
            $reason = 'series_ended';
        } elseif ($f->cancelled && $this->settings->cancelMode === 'unpublish') {
            $status = TargetRecord::DRAFT;
            $reason = 'cancelled';
        }

        $fields = [
            'title' => $f->title,
            'body' => MovedDateNote::apply($f->descriptionHtml, $note),
            'weekday' => $weekday,
            'time_start' => $series->allDay ? null : $series->patternStart->format('H:i'),
            'time_end' => $series->allDay ? null : $series->patternEnd->format('H:i'),
            'all_day' => $series->allDay,
            'room' => $f->room,
            'location' => $f->location,
            'topics' => $this->mapper->topics($f->categories),
            'target_groups' => $this->mapper->targetGroups($f->tags),
            'ferienpause' => $ferienpause,
            'abgesagt' => $abgesagt,
            'note' => $note,
            'first_date' => $first->originalDate,
            'last_date' => $series->openEnded ? null : $last->originalDate,
            'location_term' => $this->settings->locationTerm(),
        ];

        return new TargetRecord(
            TargetRecord::WEEKLY_OFFER,
            $series->syncKey() . ':' . GermanDate::code($weekday),
            $series->sourceId,
            $series->uid,
            $series->uid,
            $status,
            $reason,
            $ended,
            $first->originalStart,
            $series->openEnded ? null : $last->passedAt(),
            $fields,
            $next?->originalDate,
        );
    }

    private function datedEvent(Occurrence $o, DateTimeImmutable $now): TargetRecord
    {
        $lastDay = $o->allDay ? $o->end->modify('-1 day') : $o->end;
        if ($lastDay < $o->start) {
            $lastDay = $o->start;
        }
        $fields = [
            'title' => $o->title,
            'body' => $o->descriptionHtml,
            'date' => $o->start->format('Y-m-d'),
            'end_date' => $lastDay->format('Y-m-d'),
            'time_start' => $o->allDay ? null : $o->start->format('H:i'),
            'time_end' => $o->allDay ? null : $o->end->format('H:i'),
            'all_day' => $o->allDay,
            'room' => $o->room,
            'location' => $o->location,
            'topics' => $this->mapper->topics($o->categories),
            'target_groups' => $this->mapper->targetGroups($o->tags),
            'cancelled' => $o->cancelled,
            'location_term' => $this->settings->locationTerm(),
        ];
        $draft = $o->cancelled && $this->settings->cancelMode === 'unpublish';

        return new TargetRecord(
            TargetRecord::DATED_EVENT,
            $o->syncKey,
            $o->sourceId,
            $o->uid,
            $o->seriesUid,
            $draft ? TargetRecord::DRAFT : TargetRecord::PUBLISH,
            $draft ? 'cancelled' : null,
            $o->end <= $now,
            $o->start,
            $o->end,
            $fields,
        );
    }
}
