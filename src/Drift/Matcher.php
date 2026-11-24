<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DateTimeImmutable;
use EnterOnce\Core\Settings;
use EnterOnce\Core\Window;

/**
 * Matches what the calendar expects with what the programme page shows (plan section 4.10).
 *
 * Dated events: same day, then same start time; if several candidates remain, title similarity
 * decides, but only when it is clear (Resolver). Events on the same day whose times differ are
 * paired only if their titles are alike (at least TITLE_PAIR).
 *
 * Weekly offers: same weekday and overlapping time; then title similarity combined with how much
 * the times overlap (score = 0.6 × title + 0.4 × overlap share), again only when clear.
 * One site offer that spans several consecutive calendar series is recognised as such.
 *
 * The title alone is never the key. Anything unclear is reported as ambiguous.
 */
final class Matcher
{
    /** Events on the same day with different start times are paired only above this title similarity. */
    public const TITLE_PAIR = 0.60;
    /** A calendar series counts as part of a site offer's span only above this title similarity. */
    public const COVER_TITLE = 0.60;

    /**
     * @param list<Expected> $expected
     */
    public function match(array $expected, PageSnapshot $page, DateTimeImmutable $now, Window $window): DriftResult
    {
        $missing = [];
        $different = [];
        $onlyOnSite = [];
        $cancelledOnline = [];
        $ambiguous = [];
        $unverifiable = [];
        $agreeing = [];
        $ignored = ['past_on_site' => 0, 'beyond_window_on_site' => 0, 'pending_weekly' => 0, 'cancelled_not_on_site' => 0];

        $file = static function (Pair $pair) use (&$different, &$cancelledOnline, &$agreeing): void {
            if ($pair->cancelledOnline) {
                $cancelledOnline[] = $pair;
            } elseif ($pair->differences !== []) {
                $different[] = $pair;
            } else {
                $agreeing[] = $pair;
            }
        };

        $today = $now->setTimezone(Settings::timezone())->format('Y-m-d');
        $windowEnd = $window->end->format('Y-m-d');

        /* ---------------------------------------------------------- dated events */

        $datedExpected = array_values(array_filter($expected, static fn (Expected $e) => !$e->isWeekly()));
        $datedSite = [];
        foreach ($page->datedEvents as $s) {
            $day = $s->endDate ?? $s->date ?? '';
            if ($day < $today) {
                $ignored['past_on_site']++;
            } elseif (($s->date ?? '') >= $windowEnd) {
                $ignored['beyond_window_on_site']++;
            } else {
                $datedSite[] = $s;
            }
        }

        $matchedE = [];
        $matchedS = [];
        $inAmbiguous = [];
        $byDay = [];
        foreach ($datedExpected as $i => $e) {
            $byDay[$e->date ?? ''][0][] = $i;
        }
        foreach ($datedSite as $j => $s) {
            $byDay[$s->date ?? ''][1][] = $j;
        }
        ksort($byDay);
        foreach ($byDay as $sides) {
            $eIdx = $sides[0] ?? [];
            $sIdx = $sides[1] ?? [];
            if ($eIdx === [] || $sIdx === []) {
                continue;
            }
            // Step 1: same day and same start time. Step 2: same day, other time, alike titles.
            foreach ([1, 2] as $step) {
                $scores = [];
                foreach ($eIdx as $i) {
                    foreach ($sIdx as $j) {
                        if (isset($matchedE[$i]) || isset($matchedS[$j]) || isset($inAmbiguous['e' . $i]) || isset($inAmbiguous['s' . $j])) {
                            continue;
                        }
                        $sameStart = $datedExpected[$i]->timeStart === $datedSite[$j]->timeStart
                            || $datedExpected[$i]->allDay && $datedSite[$j]->timeStart === null;
                        $similarity = TitleSimilarity::score($datedExpected[$i]->title, $datedSite[$j]->title);
                        if ($step === 1 && $sameStart) {
                            $scores[$i][$j] = $similarity;
                        } elseif ($step === 2 && !$sameStart && $similarity >= self::TITLE_PAIR) {
                            $scores[$i][$j] = $similarity;
                        }
                    }
                }
                $resolved = Resolver::resolve($scores);
                foreach ($resolved['matches'] as [$i, $j]) {
                    $matchedE[$i] = true;
                    $matchedS[$j] = true;
                    $file($this->compare([$datedExpected[$i]], $datedSite[$j]));
                }
                foreach ($resolved['ambiguous'] as $group) {
                    foreach ($group['left'] as $i) {
                        $inAmbiguous['e' . $i] = true;
                    }
                    foreach ($group['right'] as $j) {
                        $inAmbiguous['s' . $j] = true;
                    }
                    $ambiguous[] = new AmbiguousGroup(
                        PageEvent::DATED,
                        array_map(static fn (int $i) => $datedExpected[$i], $group['left']),
                        array_map(static fn (int $j) => $datedSite[$j], $group['right']),
                    );
                }
            }
        }

        // Dated events the page may not have shown completely: what lies after the last shown day cannot be judged.
        $cutoff = $page->datedMayBeIncomplete() ? $page->lastDatedDay() : null;
        foreach ($datedExpected as $i => $e) {
            if (isset($matchedE[$i]) || isset($inAmbiguous['e' . $i])) {
                continue;
            }
            if ($e->cancelled) {
                $ignored['cancelled_not_on_site']++;
            } elseif ($cutoff !== null && ($e->date ?? '') > $cutoff) {
                $unverifiable[] = $e;
            } else {
                $missing[] = $e;
            }
        }
        foreach ($datedSite as $j => $s) {
            if (!isset($matchedS[$j]) && !isset($inAmbiguous['s' . $j])) {
                $onlyOnSite[] = $s;
            }
        }

        /* --------------------------------------------------------- weekly offers */

        $weeklyExpected = array_values(array_filter($expected, static fn (Expected $e) => $e->isWeekly()));
        $weeklySite = $page->weeklyOffers;

        $candidates = []; // [i][j] => true: same weekday, overlapping time
        $spans = static function (?string $start, ?string $end): array {
            return $start === null ? [0, 1440] : Format::span($start, $end);
        };
        foreach ($weeklyExpected as $i => $e) {
            foreach ($weeklySite as $j => $s) {
                if ($e->weekday === null || $e->weekday !== $s->weekday) {
                    continue;
                }
                [$es, $ee] = $spans($e->allDay ? null : $e->timeStart, $e->timeEnd);
                [$ss, $se] = $spans($s->timeStart, $s->timeEnd);
                if (min($ee, $se) - max($es, $ss) > 0) {
                    $candidates[$i][$j] = true;
                }
            }
        }

        $matchedWE = [];
        $matchedWS = [];

        // One site offer spanning several consecutive series of the calendar.
        $bySite = [];
        foreach ($candidates as $i => $row) {
            foreach (array_keys($row) as $j) {
                $bySite[$j][] = $i;
            }
        }
        foreach ($bySite as $j => $eIdx) {
            if (count($eIdx) < 2) {
                continue;
            }
            [$ss, $se] = $spans($weeklySite[$j]->timeStart, $weeklySite[$j]->timeEnd);
            $inside = true;
            $ranges = [];
            foreach ($eIdx as $i) {
                $e = $weeklyExpected[$i];
                [$es, $ee] = $spans($e->allDay ? null : $e->timeStart, $e->timeEnd);
                if (count($candidates[$i]) !== 1 || $es < $ss || $ee > $se
                    || TitleSimilarity::score($e->title, $weeklySite[$j]->title) < self::COVER_TITLE) {
                    $inside = false;
                    break;
                }
                $ranges[] = [$es, $ee];
            }
            if (!$inside) {
                continue;
            }
            usort($ranges, static fn (array $a, array $b) => $a <=> $b);
            for ($k = 1; $k < count($ranges); $k++) {
                if ($ranges[$k][0] < $ranges[$k - 1][1]) { // overlapping series: not a split of one block
                    $inside = false;
                }
            }
            if (!$inside) {
                continue;
            }
            foreach ($eIdx as $i) {
                $matchedWE[$i] = true;
            }
            $matchedWS[$j] = true;
            $file($this->compare(array_map(static fn (int $i) => $weeklyExpected[$i], $eIdx), $weeklySite[$j]));
        }

        $scores = [];
        foreach ($candidates as $i => $row) {
            foreach (array_keys($row) as $j) {
                if (isset($matchedWE[$i]) || isset($matchedWS[$j])) {
                    continue;
                }
                $e = $weeklyExpected[$i];
                $s = $weeklySite[$j];
                [$es, $ee] = $spans($e->allDay ? null : $e->timeStart, $e->timeEnd);
                [$ss, $se] = $spans($s->timeStart, $s->timeEnd);
                $overlap = min($ee, $se) - max($es, $ss);
                $union = max($ee, $se) - min($es, $ss);
                $scores[$i][$j] = 0.6 * TitleSimilarity::score($e->title, $s->title) + 0.4 * ($union > 0 ? $overlap / $union : 0.0);
            }
        }
        $resolved = Resolver::resolve($scores);
        $weeklyAmbiguous = [];
        foreach ($resolved['matches'] as [$i, $j]) {
            $matchedWE[$i] = true;
            $matchedWS[$j] = true;
            $file($this->compare([$weeklyExpected[$i]], $weeklySite[$j]));
        }
        foreach ($resolved['ambiguous'] as $group) {
            foreach ($group['left'] as $i) {
                $weeklyAmbiguous['e' . $i] = true;
            }
            foreach ($group['right'] as $j) {
                $weeklyAmbiguous['s' . $j] = true;
            }
            $ambiguous[] = new AmbiguousGroup(
                PageEvent::WEEKLY,
                array_map(static fn (int $i) => $weeklyExpected[$i], $group['left']),
                array_map(static fn (int $j) => $weeklySite[$j], $group['right']),
            );
        }

        $weeklyIncomplete = $page->weeklyMayBeIncomplete();
        foreach ($weeklyExpected as $i => $e) {
            if (isset($matchedWE[$i]) || isset($weeklyAmbiguous['e' . $i])) {
                continue;
            }
            if ($e->pending) {
                $ignored['pending_weekly']++;
            } elseif ($weeklyIncomplete) {
                $unverifiable[] = $e;
            } else {
                $missing[] = $e;
            }
        }
        foreach ($weeklySite as $j => $s) {
            if (!isset($matchedWS[$j]) && !isset($weeklyAmbiguous['s' . $j])) {
                $onlyOnSite[] = $s;
            }
        }

        $byOrder = static fn (Expected $a, Expected $b) => [$a->date ?? '', $a->weekday ?? 0, $a->timeStart ?? '', $a->title] <=> [$b->date ?? '', $b->weekday ?? 0, $b->timeStart ?? '', $b->title];
        usort($missing, $byOrder);
        usort($unverifiable, $byOrder);

        return new DriftResult($missing, $different, $onlyOnSite, $cancelledOnline, $ambiguous, $unverifiable, $agreeing, $ignored);
    }

    /**
     * What differs between calendar entries and one site record.
     *
     * @param list<Expected> $expected
     */
    private function compare(array $expected, PageEvent $site): Pair
    {
        $first = $expected[0];
        $weekly = $first->isWeekly();

        // Cancelled in the calendar but not on the site.
        $allCancelled = array_reduce($expected, static fn (bool $c, Expected $e) => $c && $e->cancelled, true);
        if ($allCancelled && !$site->cancelled) {
            $text = $weekly
                ? 'Der nächste Termin ist im Kalender abgesagt, die Website zeigt keinen Vermerk «abgesagt».'
                : 'Im Kalender abgesagt, auf der Website ohne Vermerk «abgesagt».';

            return new Pair($expected, $site, [$text], true);
        }

        $diff = [];
        if ($site->cancelled && !$allCancelled) {
            $diff[] = 'Die Website zeigt «abgesagt», der Kalender nicht.';
        }
        if ($weekly) {
            $calendarVacation = array_reduce($expected, static fn (bool $c, Expected $e) => $c || $e->vacation, false);
            if ($site->vacation && !$calendarVacation) {
                $diff[] = 'Die Website zeigt «Ferienpause», der Kalender nicht.';
            } elseif (!$site->vacation && $calendarVacation) {
                $diff[] = 'Im Kalender fällt der nächste Termin aus (Ferienpause), die Website zeigt keine «Ferienpause».';
            }
        }

        // Times: a single calendar entry is compared as is; several series that one offer covers as one block.
        if (!$first->allDay && $first->timeStart !== null && ($weekly || $first->endDate === null)) {
            $start = $first->timeStart;
            $end = $first->timeEnd;
            if (count($expected) > 1) {
                $ends = array_map(static fn (Expected $e) => Format::span($e->timeStart ?? '00:00', $e->timeEnd)[1], $expected);
                $starts = array_map(static fn (Expected $e) => Format::span($e->timeStart ?? '00:00', $e->timeEnd)[0], $expected);
                $start = sprintf('%02d:%02d', intdiv(min($starts), 60), min($starts) % 60);
                $endMin = max($ends);
                $end = sprintf('%02d:%02d', intdiv($endMin % 1440 === 0 ? 0 : $endMin, 60), $endMin % 60);
            }
            $calendarText = Format::times($start, $end);
            if ($site->timeStart === null) {
                $diff[] = "Die Website zeigt keine Uhrzeit (Kalender: $calendarText).";
            } elseif ($this->normalisedTimes($start, $end) !== $this->normalisedTimes($site->timeStart, $site->timeEnd)) {
                $diff[] = 'Zeit: Kalender ' . $calendarText . ', Website ' . Format::times($site->timeStart, $site->timeEnd) . '.';
            }
        }

        return new Pair($expected, $site, $diff, false);
    }

    /** @return array{int, int} */
    private function normalisedTimes(string $start, ?string $end): array
    {
        return Format::span($start, $end);
    }
}
