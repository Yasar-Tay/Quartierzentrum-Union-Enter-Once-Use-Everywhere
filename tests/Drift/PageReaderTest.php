<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use EnterOnce\Drift\PageEvent;
use EnterOnce\Drift\PageReader;
use EnterOnce\Drift\PageStructureError;

/** The reader against fixtures that are RECONSTRUCTED from docs/dev/programme-page-notes.md, not recorded. */
final class PageReaderTest extends DriftTestCase
{
    public function testReadsTheReconstructedPage(): void
    {
        $page = $this->snapshot('programm-union-reconstructed.html');

        self::assertCount(2, $page->datedEvents);
        $atelier = $page->datedEvents[0];
        self::assertSame('Atelier Cuisine Globale', $atelier->title);
        self::assertSame('2026-11-29', $atelier->date);
        self::assertSame('09:00', $atelier->timeStart);
        self::assertSame('14:00', $atelier->timeEnd);
        self::assertSame('https://www.qtp-basel.ch/events/atelier-cuisine-globale/', $atelier->url);
        self::assertFalse($atelier->cancelled);
        self::assertSame('Treffpunkt für ältere Quartierbewohner:innen', $page->datedEvents[1]->title);
        self::assertSame('2026-12-16', $page->datedEvents[1]->date);

        self::assertCount(8, $page->weeklyOffers);
        $first = $page->weeklyOffers[0];
        self::assertSame(['Deutsch im Alltag A', 1, '09:15', '11:00'], [$first->title, $first->weekday, $first->timeStart, $first->timeEnd]);
        self::assertSame('Café Bâlance', $page->weeklyOffers[1]->title);
        self::assertSame('Jugendschach & Schach- und Denkspiele', $page->weeklyOffers[3]->title, 'entities are decoded');
        self::assertSame('00:00', $page->weeklyOffers[3]->timeEnd);
        self::assertSame(2, $page->weeklyOffers[7]->weekday, 'Dienstag');
        self::assertSame(['Kinderpiazza', 'Kinderpiazza'], [$page->weeklyOffers[2]->title, $page->weeklyOffers[6]->title]);

        self::assertTrue($page->loadMoreWeekly);
        self::assertFalse($page->loadMoreDated);
        self::assertSame(8, $page->perPage);
        self::assertTrue($page->weeklyMayBeIncomplete());
        self::assertFalse($page->datedMayBeIncomplete());
        self::assertSame([], $page->unreadable);
        self::assertSame('2026-12-16', $page->lastDatedDay());
    }

    public function testPageWithoutDatedEventsIsNotAnError(): void
    {
        $page = $this->snapshot('programm-no-dated-events.html');

        self::assertSame([], $page->datedEvents);
        self::assertTrue($page->hasDatedBlock);
        self::assertCount(3, $page->weeklyOffers);
        self::assertFalse($page->loadMoreWeekly);
        self::assertFalse($page->weeklyMayBeIncomplete(), 'fewer than a page: complete');
    }

    public function testToleratesStatusBandsWhitespaceAttributesAndEntities(): void
    {
        $page = $this->snapshot('programm-variants.html');

        $repair = $page->datedEvents[0];
        self::assertSame('Repair Café', $repair->title, 'whitespace collapsed, &eacute; decoded');
        self::assertSame(['2026-12-05', '14:00', '17:00'], [$repair->date, $repair->timeStart, $repair->timeEnd], 'non-breaking spaces around the dash');
        self::assertTrue($repair->cancelled);
        self::assertFalse($repair->vacation);
        self::assertSame('https://www.qtp-basel.ch/events/repair-cafe/', $repair->url, 'single-quoted href on a heading link');

        $course = $page->datedEvents[1];
        self::assertSame('Wochenendkurs Siebdruck – Anfängerkurs', $course->title);
        self::assertSame(['2027-02-13', '2027-02-14'], [$course->date, $course->endDate]);
        self::assertSame('https://www.qtp-basel.ch/events/wochenendkurs-siebdruck/', $course->url, 'relative link resolved against the page address');

        $brunch = $page->datedEvents[2];
        self::assertSame(['Brunch & Spiele', '11:00', '12:30'], [$brunch->title, $brunch->timeStart, $brunch->timeEnd], '11.00 - 12.30 Uhr');

        self::assertSame(['Eintrag ohne Datum'], $page->unreadable);
        self::assertCount(3, $page->datedEvents);

        [$yoga, $schach, $tanz] = $page->weeklyOffers;
        self::assertTrue($yoga->vacation);
        self::assertFalse($yoga->cancelled);
        self::assertSame(4, $yoga->weekday, '«Jeden Donnerstag»');
        self::assertTrue($schach->cancelled);
        self::assertFalse($schach->vacation);
        self::assertSame(5, $schach->weekday);
        self::assertSame([6, null, null], [$tanz->weekday, $tanz->timeStart, $tanz->timeEnd], 'a weekly offer without time');
    }

    public function testRelativeLinksNeedABaseAddress(): void
    {
        $page = (new PageReader())->read(self::fixture('programm-variants.html'));

        self::assertNull($page->datedEvents[1]->url);
    }

    public function testRecordWithoutImageIsStillRead(): void
    {
        $page = $this->snapshot('programm-variants.html');

        self::assertSame('Brunch & Spiele', $page->datedEvents[2]->title);
        self::assertSame('https://www.qtp-basel.ch/events/brunch/', $page->datedEvents[2]->url);
    }

    /** @dataProvider unrecognisedPages */
    public function testRefusesPagesWhoseStructureIsNotRecognised(string $html, string $reason): void
    {
        try {
            (new PageReader())->read($html);
            self::fail('PageStructureError expected');
        } catch (PageStructureError $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringContainsString('Struktur der Programmseite wurde nicht erkannt', $e->germanMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unrecognisedPages(): iterable
    {
        yield 'new theme markup' => [self::fixture('programm-changed-structure.html'), PageStructureError::REASON_NO_CONTAINER];
        yield 'containers kept, items renamed' => [self::fixture('programm-new-item-markup.html'), PageStructureError::REASON_NO_CONTAINER];
        yield 'maintenance page' => [self::fixture('programm-maintenance.html'), PageStructureError::REASON_NO_CONTAINER];
        yield 'empty answer' => ['', PageStructureError::REASON_EMPTY];
        yield 'whitespace only' => ["  \n\t ", PageStructureError::REASON_EMPTY];
        yield 'not html at all' => ['{"error":"rest_no_route"}', PageStructureError::REASON_NO_CONTAINER];
        yield 'items present, none readable' => [
            '<div class="events-simple-programme"><div class="qtp-article-preview"><h3>Ohne Datum</h3></div></div>',
            PageStructureError::REASON_ITEMS_NOT_READABLE,
        ];
    }

    public function testLoadMoreButtonsAndPageSizeAreRead(): void
    {
        $html = '<form class="filter-form" data-perpage="12"></form>'
            . '<div class="events-simple-programme"><div class="qtp-article-preview"><h3>A</h3><span class="qtp-article-date">Mo., 04.01.2027</span></div>'
            . '<button class="qtp-events-load-more" data-events="normal">mehr</button></div>'
            . '<div class="events-listing-weekly"></div>';
        $page = (new PageReader())->read($html);

        self::assertTrue($page->loadMoreDated);
        self::assertTrue($page->datedMayBeIncomplete());
        self::assertFalse($page->loadMoreWeekly);
        self::assertSame(12, $page->perPage);
        self::assertSame(PageEvent::DATED, $page->datedEvents[0]->kind);
    }

    public function testExactlyOnePageOfWeeklyOffersMeansMoreMayExist(): void
    {
        $items = '';
        for ($i = 1; $i <= 8; $i++) {
            $items .= '<div class="qtp-article-preview"><h3>Angebot ' . $i . '</h3><span class="qtp-article-date">Montag</span><span class="qtp-article-date">10:00 - 11:00 Uhr</span></div>';
        }
        $page = (new PageReader())->read('<div class="events-listing-weekly">' . $items . '</div>');

        self::assertCount(8, $page->weeklyOffers);
        self::assertFalse($page->loadMoreWeekly);
        self::assertTrue($page->weeklyMayBeIncomplete());
    }

    public function testNoRequestIsMadeWhileReading(): void
    {
        // The reader works on a string and loads nothing: a page that links to an external DTD or
        // entity must not make libxml touch the network.
        $html = '<!DOCTYPE html SYSTEM "http://127.0.0.1:9/never.dtd"><div class="events-listing-weekly"></div>';
        $page = (new PageReader())->read($html);

        self::assertSame([], $page->weeklyOffers);
    }
}
