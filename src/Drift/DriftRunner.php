<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DateTimeImmutable;
use DateTimeZone;
use EnterOnce\Core\IntegrityError;
use InvalidArgumentException;
use Throwable;

/**
 * One drift-report run: calendar → page → comparison → HTML file.
 *
 * Order matters for R-40: everything that can fail without touching the site (address, calendar)
 * happens first; then the hourly/daily guard; then the single page request; the report file and
 * the state file are the only things written (R-41), and nothing is written when a run stops.
 */
final class DriftRunner
{
    /** @var callable(): DateTimeImmutable */
    private $clock;

    /**
     * @param (callable(): DateTimeImmutable)|null $clock the real clock, for the R-40 guard and the «Erstellt am» line
     */
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly IcsLoader $icsLoader = new IcsLoader(),
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich'));
    }

    /**
     * @param string               $icsLocation a file path or an https address
     * @param array{base?: string, centre?: string, now?: DateTimeImmutable, settings?: array<string, mixed>, source?: string, pageLabel?: string, contact?: string} $options
     */
    public function run(string $icsLocation, int $categoryId, string $outputFile, array $options = []): RunOutcome
    {
        $centre = $options['centre'] ?? PageAddress::DEFAULT_CENTRE;
        try {
            $url = PageAddress::build($options['base'] ?? PageAddress::DEFAULT_BASE, $categoryId, $centre);
        } catch (InvalidArgumentException $e) {
            return new RunOutcome(RunOutcome::USAGE, $e->getMessage());
        }
        $dir = dirname($outputFile);
        if (!is_dir($dir) || !is_writable($dir)) {
            return new RunOutcome(RunOutcome::OUTPUT_FAILED, 'Der Ordner für die Ausgabedatei existiert nicht oder ist nicht beschreibbar: ' . $dir);
        }
        $guardNow = ($this->clock)();
        $calendarNow = $options['now'] ?? $guardNow;

        // 1. The calendar (no request to the site yet).
        try {
            $ics = $this->icsLoader->load($icsLocation);
        } catch (IcsLoadError $e) {
            return new RunOutcome(RunOutcome::CALENDAR_UNAVAILABLE, $e->getMessage());
        }
        try {
            $calendar = new CalendarSide($ics, $calendarNow, $options['settings'] ?? [], $options['source'] ?? 'union');
        } catch (IntegrityError $e) {
            return new RunOutcome(RunOutcome::CALENDAR_INVALID, 'Der Kalender ist unbrauchbar: ' . $e->germanMessage() . ' Es wurde nichts verändert.');
        }

        // 2. R-40 guard, only when a request leaves the machine.
        $state = null;
        if ($this->fetcher->isLive()) {
            $state = RunState::besideOutput($outputFile);
            try {
                $refusal = $state->refusal($guardNow);
                if ($refusal !== null) {
                    return $this->refused($refusal);
                }
                $state->recordRun($guardNow);
            } catch (\RuntimeException $e) {
                return new RunOutcome(RunOutcome::OUTPUT_FAILED, $e->getMessage());
            }
        }

        // 3. The single page request.
        try {
            $response = $this->fetcher->fetch($url);
        } catch (FetchError $e) {
            return new RunOutcome(RunOutcome::FETCH_FAILED, $e->getMessage() . ' Es wurde nichts verändert.');
        }
        if ($response->isBlock()) {
            $state?->recordStop($guardNow, $response->status);

            return new RunOutcome(
                RunOutcome::BLOCKED,
                'Die Website hat die Anfrage abgelehnt (Antwort ' . $response->status . '). Der Bericht wird heute nicht mehr versucht, '
                . 'um die Website nicht zu belasten. Bitte teilen Sie dem Verband mit, was der Bericht tut, und versuchen Sie es morgen wieder.'
            );
        }
        if (!$response->isSuccess()) {
            return new RunOutcome(RunOutcome::HTTP_ERROR, 'Die Website antwortet mit einem Fehler (Antwort ' . $response->status . '). Es wurde nichts verändert.');
        }

        // 4. Read, compare, render.
        try {
            $page = (new PageReader())->read($response->body, $url);
        } catch (PageStructureError $e) {
            return new RunOutcome(RunOutcome::STRUCTURE, $e->germanMessage());
        }
        $result = (new Matcher())->match($calendar->expected, $page, $calendarNow, $calendar->window);

        $context = new ReportContext(
            $guardNow,
            $calendarNow,
            $calendar->window,
            $centre,
            $categoryId,
            IcsLoader::isAddress($icsLocation) ? 'Kalender-Adresse (wird nicht angezeigt, da sie geheim sein kann)' : 'Datei ' . basename($icsLocation),
            $this->fetcher->isLive() ? $url : ($options['pageLabel'] ?? 'gespeicherte Seite'),
            $this->fetcher->isLive() ? $url : null,
            $page,
            $calendar->summary(),
        );
        $html = (new ReportRenderer())->render($result, $context);

        $tmp = $outputFile . '.tmp';
        try {
            if (@file_put_contents($tmp, $html) === false || !@rename($tmp, $outputFile)) {
                @unlink($tmp);

                return new RunOutcome(RunOutcome::OUTPUT_FAILED, 'Die Ausgabedatei kann nicht geschrieben werden: ' . $outputFile);
            }
        } catch (Throwable) {
            return new RunOutcome(RunOutcome::OUTPUT_FAILED, 'Die Ausgabedatei kann nicht geschrieben werden: ' . $outputFile);
        }

        return new RunOutcome(RunOutcome::OK, 'Bericht geschrieben: ' . $outputFile, $result, $outputFile);
    }

    /** @param array{reason: string, until: ?DateTimeImmutable} $refusal */
    private function refused(array $refusal): RunOutcome
    {
        $until = $refusal['until'] === null ? '' : $refusal['until']->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y, H:i') . ' Uhr';

        return match ($refusal['reason']) {
            RunState::REFUSED_TOO_SOON => new RunOutcome(
                RunOutcome::TOO_SOON,
                'Der letzte Lauf liegt weniger als eine Stunde zurück. Um die Website zu schonen, läuft der Bericht höchstens einmal pro Stunde. Nächster Lauf möglich ab ' . $until . '.'
            ),
            RunState::REFUSED_STOPPED_TODAY => new RunOutcome(
                RunOutcome::STOPPED_TODAY,
                'Die Website hat heute eine Anfrage abgelehnt (Antwort 403 oder 429). Der Bericht ruft die Website heute nicht mehr ab. Nächster Versuch möglich ab ' . $until . '.'
            ),
            default => new RunOutcome(
                RunOutcome::STATE_UNREADABLE,
                'Die Statusdatei «' . RunState::FILE_NAME . '» neben der Ausgabedatei ist unlesbar. Aus Vorsicht wird die Website nicht abgerufen. '
                . 'Prüfen Sie die Datei; wenn Sie sicher sind, dass in der letzten Stunde kein Lauf stattfand, löschen Sie sie.'
            ),
        };
    }
}
