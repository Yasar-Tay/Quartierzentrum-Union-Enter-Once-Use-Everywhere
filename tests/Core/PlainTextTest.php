<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use EnterOnce\Core\PlainText;

/** Review D3: title, room and location are plain text in every record and in the feed. */
final class PlainTextTest extends CoreTestCase
{
    public function testLine(): void
    {
        self::assertSame('Konzert', PlainText::line('Konzert <img src=x onerror=alert(1)>'));
        self::assertSame('Konzert im Hof', PlainText::line('Konzert <b>im</b>  Hof'));
        self::assertSame('Café & Bar', PlainText::line('Café &amp; Bar'));
        self::assertSame('&amp;', PlainText::line('&amp;amp;'), 'entities are decoded once');
        self::assertSame('Kinder <3 Spiel', PlainText::line('Kinder <3 Spiel'));
        self::assertSame('a b', PlainText::line("a\t\n b<script>alert(1)</script>"));
    }

    public function testHtmlInTheCalendarNeverReachesRecordsOrFeed(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:xss@example.org\r\n"
            . "SUMMARY:Konzert <img src=x onerror=alert(1)> &amp; Tanz\r\n"
            . "LOCATION:<b>Grosser Saal</b>\\, Klybeckstrasse 95\r\n"
            . "DTSTART;TZID=Europe/Zurich:20270120T190000\r\nDTEND;TZID=Europe/Zurich:20270120T210000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $core = self::core();
        $r = $core->read($ics, self::at('2027-01-04 00:00'), \EnterOnce\Core\Window::fromDates('2027-01-04', '2027-03-31'));
        $o = $r->occurrences[0];
        self::assertSame('Konzert & Tanz', $o->title);
        self::assertSame('Grosser Saal', $o->room);
        self::assertSame('Grosser Saal, Klybeckstrasse 95', $o->location);
        $t = $core->targets($r)->targets[0];
        self::assertSame('Konzert & Tanz', $t->fields['title']);
        $feed = $core->feed($r->occurrences);
        self::assertStringNotContainsString('<', $feed[0]['title'] . $feed[0]['room'] . $feed[0]['location']);
    }
}
