<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use DateTimeImmutable;
use EnterOnce\Core\Action;
use EnterOnce\Core\CalendarResult;
use EnterOnce\Core\Core;
use EnterOnce\Core\Occurrence;
use EnterOnce\Core\Settings;
use EnterOnce\Core\SiteRecord;
use EnterOnce\Core\SyncPlan;
use EnterOnce\Core\TargetRecord;
use EnterOnce\Core\TargetSet;
use EnterOnce\Core\Window;
use PHPUnit\Framework\TestCase;

abstract class CoreTestCase extends TestCase
{
    protected const SAMPLE = 'union-beispielkalender.ics';
    protected const LIVE = 'calendar.ics';

    protected static function fixture(string $name): string
    {
        $text = file_get_contents(dirname(__DIR__) . '/fixtures/' . $name);
        self::assertIsString($text);

        return $text;
    }

    protected static function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, Settings::timezone());
    }

    /** @param array<string, mixed> $settings */
    protected static function core(array $settings = [], string $source = 'union'): Core
    {
        return new Core($settings, $source);
    }

    /** @param array<string, mixed> $settings */
    protected static function read(string $ics, string $now, string $from, string $to, array $settings = []): CalendarResult
    {
        return self::core($settings)->read($ics, self::at($now), Window::fromDates($from, $to));
    }

    /** @param array<string, mixed> $settings */
    protected static function targets(string $ics, string $now, string $from, string $to, array $settings = []): TargetSet
    {
        $core = self::core($settings);

        return $core->targets($core->read($ics, self::at($now), Window::fromDates($from, $to)));
    }

    /**
     * One full run: read, targets, diff against $site; returns the plan and the site after applying it.
     *
     * @param list<SiteRecord> $site
     * @param array<string, mixed> $settings
     * @return array{0: SyncPlan, 1: list<SiteRecord>}
     */
    protected static function sync(string $ics, string $now, string $from, string $to, array $site = [], array $settings = [], bool $releaseHeld = false): array
    {
        $core = self::core($settings);
        $result = $core->read($ics, self::at($now), Window::fromDates($from, $to));
        $plan = $core->diff($core->targets($result), $site, $releaseHeld);

        return [$plan, self::apply($plan, $site)];
    }

    /**
     * Applies a plan the way the WordPress shell would (ignoring dry run).
     *
     * @param list<SiteRecord> $site
     * @return list<SiteRecord>
     */
    protected static function apply(SyncPlan $plan, array $site): array
    {
        $byKey = [];
        foreach ($site as $r) {
            $byKey[$r->key] = $r;
        }
        foreach ($plan->actions as $a) {
            $existing = $a->siteKey !== null ? ($byKey[$a->siteKey] ?? null) : null;
            switch ($a->type) {
                case Action::CREATE:
                    $byKey[$a->key] = SiteRecord::fromTarget($a->target);
                    break;
                case Action::UPDATE:
                    unset($byKey[$a->siteKey]);
                    $byKey[$a->key] = SiteRecord::fromTarget($a->target, $existing?->keepText ?? false);
                    break;
                case Action::UNPUBLISH:
                    $byKey[$a->siteKey] = self::with($existing, TargetRecord::DRAFT, $a->missingRuns);
                    break;
                case Action::KEEP:
                case Action::HOLD:
                    $byKey[$a->siteKey] = self::with($existing, $existing->status, $a->missingRuns);
                    break;
            }
        }

        return array_values($byKey);
    }

    protected static function with(SiteRecord $r, string $status, int $missingRuns): SiteRecord
    {
        return new SiteRecord($r->key, $r->kind, $r->hash, $status, $missingRuns, $r->source, $r->managed, $r->keepText, $r->flags, $r->start, $r->end, $r->title, $r->weekday, $r->timeStart, $r->id);
    }

    /**
     * @param list<Occurrence> $occurrences
     * @return list<Occurrence>
     */
    protected static function byTitle(array $occurrences, string $title): array
    {
        return array_values(array_filter($occurrences, static fn (Occurrence $o) => $o->title === $title));
    }

    /** @param list<TargetRecord> $targets */
    protected static function targetByTitle(array $targets, string $title): ?TargetRecord
    {
        foreach ($targets as $t) {
            if ($t->fields['title'] === $title) {
                return $t;
            }
        }

        return null;
    }

    /** Remove the VEVENT with the given UID (all its components) from an ICS text. */
    protected static function withoutEvent(string $ics, string $uid): string
    {
        $out = preg_replace('/BEGIN:VEVENT\r?\n(?:(?!END:VEVENT).)*?UID:' . preg_quote($uid, '/') . '\r?\n.*?END:VEVENT\r?\n/s', '', $ics);
        self::assertIsString($out);
        self::assertNotSame($ics, $out, "Event $uid not found");

        return $out;
    }

    /** Replace within the VEVENT blocks of one UID only. */
    protected static function inEvent(string $ics, string $uid, string $search, string $replace): string
    {
        $count = 0;
        $out = preg_replace_callback(
            '/BEGIN:VEVENT\r?\n.*?END:VEVENT\r?\n/s',
            static function (array $m) use ($uid, $search, $replace, &$count) {
                if (!str_contains($m[0], 'UID:' . $uid . "\n") && !str_contains($m[0], 'UID:' . $uid . "\r\n")) {
                    return $m[0];
                }
                $new = str_replace($search, $replace, $m[0], $n);
                $count += $n;

                return $new;
            },
            $ics
        );
        self::assertGreaterThan(0, $count, "'$search' not found in $uid");

        return (string) $out;
    }

    protected static function insertBeforeEnd(string $ics, string $vevent): string
    {
        return str_replace("END:VCALENDAR", rtrim($vevent) . "\nEND:VCALENDAR", $ics);
    }
}
