<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use DateTimeImmutable;
use DateTimeZone;
use EnterOnce\Core\Window;
use EnterOnce\Drift\Expected;
use EnterOnce\Drift\PageEvent;
use EnterOnce\Drift\PageReader;
use EnterOnce\Drift\PageSnapshot;
use PHPUnit\Framework\TestCase;

/** Helpers shared by the drift tests. No test in this suite opens a socket. */
abstract class DriftTestCase extends TestCase
{
    protected static function fixturePath(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/programm/' . $name;
    }

    protected static function fixture(string $name): string
    {
        $text = file_get_contents(self::fixturePath($name));
        self::assertIsString($text);

        return $text;
    }

    protected static function zurich(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Europe/Zurich'));
    }

    protected function snapshot(string $fixture): PageSnapshot
    {
        return (new PageReader())->read(self::fixture($fixture), 'https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union');
    }

    /** @param list<PageEvent> $dated @param list<PageEvent> $weekly */
    protected static function page(array $dated = [], array $weekly = [], bool $moreWeekly = false, bool $moreDated = false): PageSnapshot
    {
        return new PageSnapshot($dated, $weekly, [], $moreDated, $moreWeekly, 8, true, true);
    }

    protected static function siteDated(string $title, string $date, ?string $start, ?string $end = null, bool $cancelled = false, int $i = 0): PageEvent
    {
        return new PageEvent(PageEvent::DATED, $title, 'https://www.qtp-basel.ch/events/' . $i . '/', $date, null, null, $start, $end, $cancelled, false, $i);
    }

    protected static function siteWeekly(string $title, int $weekday, ?string $start, ?string $end = null, bool $cancelled = false, bool $vacation = false, int $i = 0): PageEvent
    {
        return new PageEvent(PageEvent::WEEKLY, $title, 'https://www.qtp-basel.ch/events/w' . $i . '/', null, null, $weekday, $start, $end, $cancelled, $vacation, $i);
    }

    protected static function calDated(string $title, string $date, ?string $start, ?string $end = null, bool $cancelled = false, bool $allDay = false): Expected
    {
        return new Expected(PageEvent::DATED, "k:$title:$date", $title, $date, null, null, $start, $end, $allDay, $cancelled, false);
    }

    protected static function calWeekly(string $title, int $weekday, string $start, string $end, bool $cancelled = false, bool $vacation = false, bool $pending = false): Expected
    {
        return new Expected(PageEvent::WEEKLY, "k:$title:$weekday", $title, '2026-11-23', null, $weekday, $start, $end, false, $cancelled, $vacation, $pending);
    }

    protected static function window(string $now = '2026-11-23 00:00'): Window
    {
        return Window::monthsAhead(self::zurich($now), 3);
    }

    /** @return list<string> */
    protected static function titles(array $items): array
    {
        return array_map(static fn ($i) => $i->title, $items);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/eo-drift-' . bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    protected function removeDir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($dir);
    }
}
