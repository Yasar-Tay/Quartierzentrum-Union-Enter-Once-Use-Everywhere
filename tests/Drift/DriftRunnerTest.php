<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use DateTimeImmutable;
use EnterOnce\Drift\DriftRunner;
use EnterOnce\Drift\FetchError;
use EnterOnce\Drift\FilePageFetcher;
use EnterOnce\Drift\IcsLoader;
use EnterOnce\Drift\PageResponse;
use EnterOnce\Drift\RunOutcome;
use EnterOnce\Drift\RunState;

/** End-to-end runs with fixtures and fake fetchers (plan 8.7 and rules R-40, R-41). */
final class DriftRunnerTest extends DriftTestCase
{
    private const ICS = 'drift-calendar.ics';
    private const NOW = '2026-11-23 00:00';

    private string $dir;
    /** The "real clock" of the guard, moved by the tests. */
    private DateTimeImmutable $clock;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->clock = self::zurich('2026-11-24 09:00');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function runner(\EnterOnce\Drift\PageFetcher $fetcher, ?IcsLoader $ics = null): DriftRunner
    {
        return new DriftRunner($fetcher, $ics ?? new IcsLoader(), fn (): DateTimeImmutable => $this->clock);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return ['now' => self::zurich(self::NOW)];
    }

    private function out(): string
    {
        return $this->dir . '/bericht.html';
    }

    private function icsPath(): string
    {
        return self::fixturePath(self::ICS);
    }

    private function runFile(string $pageFixture = 'programm-union-reconstructed.html'): RunOutcome
    {
        return $this->runner(new FilePageFetcher(self::fixturePath($pageFixture)))->run($this->icsPath(), 52, $this->out(), $this->options() + ['pageLabel' => 'Datei ' . $pageFixture]);
    }

    /* ---------- the comparison, end to end ---------- */

    public function testWholeComparisonOfTheReconstructedPageWithTheTestCalendar(): void
    {
        $outcome = $this->runFile();

        self::assertTrue($outcome->isOk(), $outcome->message);
        $r = $outcome->result;
        self::assertNotNull($r);

        self::assertSame(['Repair Café'], self::titles($r->missing));
        self::assertSame(
            ['Atelier Cuisine Globale', 'Deutsch im Alltag', 'Yoga im Union'],
            array_map(static fn ($p) => $p->expected[0]->title, $r->different)
        );
        self::assertSame(['Treffpunkt für ältere Quartierbewohner:innen'], array_map(static fn ($p) => $p->expected[0]->title, $r->cancelledOnline));
        self::assertSame(['Deutsch im Alltag B', 'Wir reden Deutsch'], self::titles($r->onlyOnSite));
        self::assertSame(['Theaterprobe'], self::titles($r->unverifiable), 'weekly list incomplete (mehr laden)');
        self::assertCount(1, $r->ambiguous, 'the two Kinderpiazza offers');
        self::assertSame(['Kinderpiazza'], self::titles($r->ambiguous[0]->expected));
        self::assertSame(['Kinderpiazza', 'Kinderpiazza'], self::titles($r->ambiguous[0]->site));
        self::assertCount(2, $r->agreeing, 'Café Balance, and Jugendschach + Schach- und Denkspiele as one offer');
        self::assertSame(
            ['Zeit: Kalender 09:00–15:00 Uhr, Website 09:00–14:00 Uhr.'],
            $r->different[0]->differences
        );
    }

    public function testReportIsASelfContainedGermanDocumentWithTheRequiredParts(): void
    {
        self::assertTrue($this->runFile()->isOk());
        $html = (string) file_get_contents($this->out());

        // Self-contained: no script, no external resource, no inline handler.
        self::assertDoesNotMatchRegularExpression('/<script|<iframe|<link\b|<img\b|@import|url\(|\son[a-z]+\s*=|\ssrc\s*=/i', $html);
        self::assertStringContainsString('<html lang="de-CH">', $html);
        self::assertStringNotContainsString('ß', $html, 'Swiss spelling');

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xp = new \DOMXPath($doc);
        self::assertSame(1, $xp->query('//h1')->length);
        self::assertGreaterThanOrEqual(7, $xp->query('//section[@aria-labelledby]')->length);
        foreach ($xp->query('//section[@aria-labelledby]') as $section) {
            self::assertNotNull($doc->getElementById($section->getAttribute('aria-labelledby')), 'every section is named by its heading');
        }
        self::assertSame(0, $xp->query('//table//th[not(@scope)]')->length, 'table headers have a scope');
        self::assertGreaterThan(0, $xp->query('//table/caption')->length);

        // The four lists, the ambiguous section and the unverifiable section.
        foreach (['Fehlt auf der Website', 'Auf der Website anders', 'Auf der Website, aber nicht im Kalender', 'Abgesagt, aber noch online', 'Nicht eindeutig zuzuordnen', 'Nicht beurteilbar'] as $heading) {
            self::assertStringContainsString($heading, $html);
        }

        // Top of the report: when, which calendar and centre, how many weekly offers, and that there may be more.
        self::assertStringContainsString('Erstellt am', $html);
        self::assertStringContainsString('24.11.2026, 09:00 Uhr', $html);
        self::assertStringContainsString('Datei drift-calendar.ics', $html);
        self::assertStringContainsString('Quartierzentrum Union (location_cat 52)', $html);
        self::assertStringContainsString('Die Programmseite zeigte 8 wöchentliche Angebote.', $html);
        self::assertStringContainsString('weitere wöchentliche Angebote, die dieser Bericht nicht sehen konnte', $html);

        // Each entry links to its record on the site.
        self::assertStringContainsString('href="https://www.qtp-basel.ch/events/atelier-cuisine-globale/"', $html);
        self::assertStringContainsString('href="https://www.qtp-basel.ch/events/spielend-deutsch-lernen-6/"', $html);
        self::assertStringContainsString('href="https://www.qtp-basel.ch/events/kinderpiazza-17/"', $html);
        self::assertStringContainsString('href="https://www.qtp-basel.ch/events/kinderpiazza-saisonbeginn/"', $html);
        self::assertStringContainsString('Zeit: Kalender 09:00–11:00 Uhr, Website 09:15–11:00 Uhr.', $html);
        self::assertStringContainsString('Ferienpause', $html);
    }

    public function testReportNeverContainsPersonDataFromTheCalendar(): void
    {
        self::assertTrue($this->runFile()->isOk());
        $html = (string) file_get_contents($this->out());

        // R-12: the calendar entry «Repair Café» carries ORGANIZER and ATTENDEE lines.
        foreach (['Erika', 'Beispiel', 'Max Muster', 'muster', 'example.invalid', 'ORGANIZER', 'ATTENDEE', 'mailto'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
        self::assertStringContainsString('Repair Café', $html, 'the event itself is there');
        self::assertStringNotContainsString('Teamsitzung', $html, 'internal entries (R-01) are not shown');
    }

    public function testACalendarAddressIsNeverPrintedInTheReport(): void
    {
        $address = 'https://calendar.example.org/private/abc123secret/basic.ics';
        $ics = new IcsLoader(fn (string $url): string => (string) file_get_contents($this->icsPath()), fn (string $host): array => ['93.184.216.34']);
        $outcome = $this->runner(new FilePageFetcher(self::fixturePath('programm-union-reconstructed.html')), $ics)->run($address, 52, $this->out(), $this->options());

        self::assertTrue($outcome->isOk(), $outcome->message);
        $html = (string) file_get_contents($this->out());
        self::assertStringNotContainsString('abc123secret', $html);
        self::assertStringNotContainsString('calendar.example.org', $html);
        self::assertStringContainsString('nicht angezeigt', $html);
    }

    public function testACleanComparisonSaysSo(): void
    {
        $html = $this->runFile('programm-no-dated-events.html');
        self::assertTrue($html->isOk());
        $text = (string) file_get_contents($this->out());
        // This page does not match the calendar, so findings exist; the wording for «none» is tested directly:
        self::assertStringContainsString('Bitte schauen Sie sich die Listen', $text);

        $renderer = new \EnterOnce\Drift\ReportRenderer();
        $calendar = new \EnterOnce\Drift\CalendarSide((string) file_get_contents($this->icsPath()), self::zurich(self::NOW));
        $empty = new \EnterOnce\Drift\DriftResult([], [], [], [], [], [], [], []);
        $clean = $renderer->render($empty, new \EnterOnce\Drift\ReportContext($this->clock, self::zurich(self::NOW), $calendar->window, 'Quartierzentrum Union', 52, 'x.ics', 'Datei x.html', null, self::page(), $calendar->summary()));
        self::assertStringContainsString('Keine Abweichungen gefunden', $clean);
        self::assertStringContainsString('Keine Einträge.', $clean);
    }

    public function testReportEscapesWhatComesFromThePage(): void
    {
        $html = '<div class="events-simple-programme"><div class="qtp-article-preview"><a href="https://www.qtp-basel.ch/events/x/"><h3>&lt;script&gt;alert(1)&lt;/script&gt; &quot;Fest&quot;</h3></a>'
            . '<span class="qtp-article-date">Do., 26.11.2026</span><span class="qtp-article-date">10:00 - 11:00</span></div></div>';
        $fetcher = new FakeFetcher([FakeFetcher::ok($html)], live: false);
        $outcome = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());

        self::assertTrue($outcome->isOk(), $outcome->message);
        $report = (string) file_get_contents($this->out());
        self::assertStringNotContainsString('<script>', $report);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $report);
    }

    /* ---------- R-40 ---------- */

    public function testALiveRunMakesExactlyOnePlainRequestToTheProgrammePage(): void
    {
        $fetcher = new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]);
        $outcome = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());

        self::assertTrue($outcome->isOk(), $outcome->message);
        self::assertSame(['https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union'], $fetcher->urls);
        self::assertStringNotContainsString('admin-ajax', $fetcher->urls[0]);
        self::assertStringNotContainsString('filter_events', $fetcher->urls[0]);
        self::assertFileExists($this->dir . '/' . RunState::FILE_NAME);
    }

    public function testASecondRunWithinTheHourIsRefusedWithoutARequest(): void
    {
        $fetcher = new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]);
        self::assertTrue($this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options())->isOk());
        $firstReport = (string) file_get_contents($this->out());

        $this->clock = $this->clock->modify('+59 minutes');
        $second = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());

        self::assertSame(RunOutcome::TOO_SOON, $second->status);
        self::assertStringContainsString('höchstens einmal pro Stunde', $second->message);
        self::assertStringContainsString('10:00 Uhr', $second->message, 'says when the next run is possible');
        self::assertCount(1, $fetcher->urls, 'no second request');
        self::assertSame($firstReport, file_get_contents($this->out()), 'the report is untouched');

        $this->clock = $this->clock->modify('+2 minutes'); // 61 minutes after the first run
        self::assertTrue($this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options())->isOk());
        self::assertCount(2, $fetcher->urls);
    }

    public function testTheHourlyLimitAppliesToEveryReportNameInTheSameFolder(): void
    {
        $fetcher = new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]);
        self::assertTrue($this->runner($fetcher)->run($this->icsPath(), 52, $this->dir . '/a.html', $this->options())->isOk());
        $again = $this->runner($fetcher)->run($this->icsPath(), 52, $this->dir . '/b.html', $this->options());

        self::assertSame(RunOutcome::TOO_SOON, $again->status);
        self::assertFileDoesNotExist($this->dir . '/b.html');
    }

    /** @dataProvider blockingStatuses */
    public function testA403Or429StopsAndNoFurtherRunHappensThatDay(int $status): void
    {
        $fetcher = new FakeFetcher([new PageResponse($status, 'Forbidden')]);
        $first = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());

        self::assertSame(RunOutcome::BLOCKED, $first->status);
        self::assertStringContainsString("Antwort $status", $first->message);
        self::assertFileDoesNotExist($this->out());
        $state = json_decode((string) file_get_contents($this->dir . '/' . RunState::FILE_NAME), true);
        self::assertSame('2026-11-24', $state['stop_day']);
        self::assertSame($status, $state['stop_status']);

        // Hours later, the same day: refused, no request.
        $this->clock = $this->clock->modify('+10 hours'); // 19:00
        $later = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());
        self::assertSame(RunOutcome::STOPPED_TODAY, $later->status);
        self::assertStringContainsString('25.11.2026, 00:00 Uhr', $later->message);
        self::assertCount(1, $fetcher->urls);

        // The next day it may try again.
        $this->clock = self::zurich('2026-11-25 08:00');
        $next = $this->runner(new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]))->run($this->icsPath(), 52, $this->out(), $this->options());
        self::assertTrue($next->isOk(), $next->message);
    }

    /** @return iterable<string, array{int}> */
    public static function blockingStatuses(): iterable
    {
        yield '403' => [403];
        yield '429' => [429];
    }

    public function testOtherErrorsDoNotTriggerTheDayStopButStillCountAsARun(): void
    {
        $fetcher = new FakeFetcher([new PageResponse(503, 'down')]);
        $outcome = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());

        self::assertSame(RunOutcome::HTTP_ERROR, $outcome->status);
        $state = json_decode((string) file_get_contents($this->dir . '/' . RunState::FILE_NAME), true);
        self::assertArrayNotHasKey('stop_day', $state);
        self::assertSame(RunOutcome::TOO_SOON, $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options())->status);
    }

    public function testAnUnreadableStateFileBlocksTheRequest(): void
    {
        file_put_contents($this->dir . '/' . RunState::FILE_NAME, '{not json');
        $fetcher = new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]);
        $outcome = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());

        self::assertSame(RunOutcome::STATE_UNREADABLE, $outcome->status);
        self::assertSame([], $fetcher->urls);
    }

    public function testRunsFromASavedPageFileAreNotLimitedAndWriteNoStateFile(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($this->runFile()->isOk());
        }
        self::assertFileDoesNotExist($this->dir . '/' . RunState::FILE_NAME);
    }

    public function testTheCalendarIsCheckedBeforeTheSiteIsAsked(): void
    {
        $fetcher = new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]);
        $outcome = $this->runner($fetcher)->run($this->dir . '/gibt-es-nicht.ics', 52, $this->out(), $this->options());

        self::assertSame(RunOutcome::CALENDAR_UNAVAILABLE, $outcome->status);
        self::assertSame([], $fetcher->urls, 'no request, and the failed run did not count against the hour');
        self::assertFileDoesNotExist($this->dir . '/' . RunState::FILE_NAME);
    }

    public function testABrokenCalendarStopsBeforeAnyRequest(): void
    {
        file_put_contents($this->dir . '/kaputt.ics', '<html>Fehler 404</html>');
        $fetcher = new FakeFetcher([FakeFetcher::ok('x')]);
        $outcome = $this->runner($fetcher)->run($this->dir . '/kaputt.ics', 52, $this->out(), $this->options());

        self::assertSame(RunOutcome::CALENDAR_INVALID, $outcome->status);
        self::assertStringContainsString('Es wurde nichts verändert', $outcome->message);
        self::assertSame([], $fetcher->urls);
    }

    /* ---------- other stops, and R-41 ---------- */

    public function testStructureNotRecognisedStopsClearlyAndWritesNoReport(): void
    {
        file_put_contents($this->out(), 'ALTER BERICHT');
        foreach (['programm-changed-structure.html', 'programm-new-item-markup.html', 'programm-maintenance.html'] as $fixture) {
            $outcome = $this->runFile($fixture);
            self::assertSame(RunOutcome::STRUCTURE, $outcome->status, $fixture);
            self::assertStringContainsString('Struktur der Programmseite wurde nicht erkannt', $outcome->message);
            self::assertSame('ALTER BERICHT', file_get_contents($this->out()), 'the old report is not overwritten');
        }
    }

    public function testATransportFailureIsReportedAndNothingIsWritten(): void
    {
        $fetcher = new FakeFetcher([new FetchError('Keine Antwort von der Website.')]);
        $outcome = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options());

        self::assertSame(RunOutcome::FETCH_FAILED, $outcome->status);
        self::assertFileDoesNotExist($this->out());
    }

    public function testOnlyTheReportAndTheStateFileAreWritten(): void
    {
        $fetcher = new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]);
        self::assertTrue($this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), $this->options())->isOk());

        $files = array_map('basename', glob($this->dir . '/*') ?: []);
        sort($files);
        self::assertSame(['bericht.html', RunState::FILE_NAME], $files);
    }

    public function testInvalidBaseAddressesAndMissingFoldersAreUsageErrors(): void
    {
        $fetcher = new FakeFetcher([FakeFetcher::ok('x')]);
        $http = $this->runner($fetcher)->run($this->icsPath(), 52, $this->out(), ['base' => 'http://www.qtp-basel.ch'] + $this->options());
        self::assertSame(RunOutcome::USAGE, $http->status);
        self::assertStringContainsString('https://', $http->message);

        $folder = $this->runner($fetcher)->run($this->icsPath(), 52, $this->dir . '/nope/b.html', $this->options());
        self::assertSame(RunOutcome::OUTPUT_FAILED, $folder->status);
        self::assertSame([], $fetcher->urls);
    }

    public function testBaseAddressAndCentreNameShapeTheRequest(): void
    {
        $fetcher = new FakeFetcher([FakeFetcher::ok(self::fixture('programm-union-reconstructed.html'))]);
        $this->runner($fetcher)->run($this->icsPath(), 77, $this->out(), ['base' => 'https://test.example/verband/', 'centre' => 'Treff Süd'] + $this->options());

        self::assertSame(['https://test.example/verband/programm/?location_cat[77]=Treff%20S%C3%BCd'], $fetcher->urls);
    }
}
