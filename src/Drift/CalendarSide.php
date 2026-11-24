<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DateTimeImmutable;
use EnterOnce\Core\CalendarResult;
use EnterOnce\Core\Core;
use EnterOnce\Core\IntegrityError;
use EnterOnce\Core\TargetRecord;
use EnterOnce\Core\Window;

/**
 * The calendar's side of the comparison: ICS text in, "what should be on the site" out.
 * All rules (screening, series, weekly offers, cancellations) are the core's; this class only
 * calls it and copies the few fields the comparison needs.
 */
final class CalendarSide
{
    /** @var list<Expected> */
    public readonly array $expected;
    public readonly CalendarResult $result;
    public readonly Window $window;

    /**
     * @param array<string, mixed> $settings core settings (`enteronce_settings` shape); `cancel_mode` is always `badge`
     *
     * @throws IntegrityError
     */
    public function __construct(string $icsText, DateTimeImmutable $now, array $settings = [], string $sourceId = 'union')
    {
        // `badge`: a cancelled event stays a record with `cancelled: true`, so that «cancelled but
        // still online» can be found. With `unpublish` the core would drop it from the targets.
        $settings['cancel_mode'] = 'badge';
        $core = new Core($settings, $sourceId);
        $this->window = Window::monthsAhead($now, $core->settings->windowMonthsAhead);
        $this->result = $core->read($icsText, $now, $this->window);
        $targets = $core->targets($this->result);

        $expected = [];
        foreach ($targets->targets as $t) {
            if ($t->status !== TargetRecord::PUBLISH || $t->past) {
                continue;
            }
            $expected[] = $this->convert($t, false);
        }
        foreach ($targets->pending as $t) {
            $expected[] = $this->convert($t, true);
        }
        $this->expected = $expected;
    }

    private function convert(TargetRecord $t, bool $pending): Expected
    {
        $f = $t->fields;
        $timeStart = isset($f['time_start']) && is_string($f['time_start']) ? $f['time_start'] : null;
        $timeEnd = isset($f['time_end']) && is_string($f['time_end']) ? $f['time_end'] : null;
        $allDay = ($f['all_day'] ?? false) === true;

        if ($t->kind === TargetRecord::WEEKLY_OFFER) {
            return new Expected(
                PageEvent::WEEKLY,
                $t->key,
                (string) ($f['title'] ?? ''),
                isset($f['first_date']) && is_string($f['first_date']) ? $f['first_date'] : null,
                null,
                isset($f['weekday']) ? (int) $f['weekday'] : null,
                $timeStart,
                $timeEnd,
                $allDay,
                ($f['abgesagt'] ?? false) === true,
                ($f['ferienpause'] ?? false) === true,
                $pending,
                (string) ($f['room'] ?? ''),
                $t->nextDate,
            );
        }

        $date = isset($f['date']) && is_string($f['date']) ? $f['date'] : $t->start->format('Y-m-d');
        $endDate = isset($f['end_date']) && is_string($f['end_date']) ? $f['end_date'] : null;

        return new Expected(
            PageEvent::DATED,
            $t->key,
            (string) ($f['title'] ?? ''),
            $date,
            $endDate !== null && $endDate !== $date ? $endDate : null,
            null,
            $timeStart,
            // On a multi-day event the core's end time belongs to the last day; the page shows one range.
            $timeEnd,
            $allDay,
            ($f['cancelled'] ?? false) === true,
            false,
            false,
            (string) ($f['room'] ?? ''),
        );
    }

    /** @return array<string, int> counts shown at the top of the report */
    public function summary(): array
    {
        $dated = 0;
        $weekly = 0;
        foreach ($this->expected as $e) {
            $e->isWeekly() ? $weekly++ : $dated++;
        }

        return [
            'dated_events' => $dated,
            'weekly_offers' => $weekly,
            'dropped_internal' => $this->result->droppedInternal,
        ];
    }
}
