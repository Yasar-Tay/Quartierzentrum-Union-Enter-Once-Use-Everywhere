<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use EnterOnce\Drift\RunState;

/**
 * Runs bin/enteronce-drift as a child process. Every call here either stops before any request
 * or reads a saved page file (--page-file): no test starts a live run.
 */
final class CliTest extends DriftTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function cli(array $args, array $env = []): array
    {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/enteronce-drift'], $args);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env === [] ? null : $env + ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);

        return [$code, $stdout, $stderr];
    }

    private function ics(): string
    {
        return self::fixturePath('drift-calendar.ics');
    }

    public function testWritesTheReportFromASavedPage(): void
    {
        [$code, $out, $err] = $this->cli([$this->ics(), '52', $this->dir . '/b.html', '--page-file=' . self::fixturePath('programm-union-reconstructed.html'), '--now=2026-11-23T00:00']);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Bericht geschrieben', $out);
        self::assertStringContainsString('Fehlt auf der Website: 1 | Auf der Website anders: 3 | Nur auf der Website: 2 | Abgesagt, aber online: 1 | Nicht eindeutig: 1 | Nicht beurteilbar: 1 | In Ordnung: 2', $out);
        self::assertFileExists($this->dir . '/b.html');
        self::assertFileDoesNotExist($this->dir . '/' . RunState::FILE_NAME);
        self::assertSame(['b.html'], array_map('basename', glob($this->dir . '/*') ?: []), 'nothing else is written');
    }

    public function testStopOnAnUnrecognisedPageExitsWith2AndWritesNothing(): void
    {
        [$code, , $err] = $this->cli([$this->ics(), '52', $this->dir . '/b.html', '--page-file=' . self::fixturePath('programm-changed-structure.html'), '--now=2026-11-23T00:00']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Struktur der Programmseite wurde nicht erkannt', $err);
        self::assertSame([], glob($this->dir . '/*'));
    }

    /** @dataProvider wrongUsage */
    public function testWrongUsageGetsAGermanMessageAndExitCode1(array $args, string $expected): void
    {
        [$code, $out, $err] = $this->cli($args);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString($expected, $err);
        self::assertStringContainsString('Aufruf: bin/php bin/enteronce-drift', $err);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function wrongUsage(): iterable
    {
        $ics = self::fixturePath('drift-calendar.ics');
        yield 'no arguments' => [[], 'genau drei Angaben'];
        yield 'two arguments' => [[$ics, '52'], 'genau drei Angaben'];
        yield 'category not a number' => [[$ics, 'union', 'b.html'], 'positive ganze Zahl'];
        yield 'category zero' => [[$ics, '0', 'b.html'], 'positive ganze Zahl'];
        yield 'unknown option' => [[$ics, '52', 'b.html', '--fast'], 'Unbekannte Option: --fast'];
        yield 'unknown option with value' => [[$ics, '52', 'b.html', '--tempo=3'], 'Unbekannte Option: --tempo'];
        yield 'bad now' => [[$ics, '52', 'b.html', '--now=gestern-abend-irgendwann', '--page-file=x'], 'bei --now ist ungültig'];
        yield 'unset env variable' => [['env:ENTERONCE_NICHT_GESETZT', '52', 'b.html'], 'nicht gesetzt'];
        yield 'bad settings file' => [[$ics, '52', 'b.html', '--settings=/gibt/es/nicht.json', '--page-file=x'], 'Einstellungsdatei'];
    }

    public function testNonHttpsBaseStopsBeforeAnyRequest(): void
    {
        [$code, , $err] = $this->cli([$this->ics(), '52', $this->dir . '/b.html', '--base=http://www.qtp-basel.ch']);

        self::assertSame(1, $code);
        self::assertStringContainsString('https://', $err);
    }

    public function testMissingCalendarFileStopsBeforeAnyRequest(): void
    {
        [$code, , $err] = $this->cli([$this->dir . '/weg.ics', '52', $this->dir . '/b.html']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Die Kalenderdatei kann nicht gelesen werden', $err);
        self::assertSame([], glob($this->dir . '/*'), 'no state file: the site was not asked');
    }

    public function testMissingOutputFolderStopsBeforeAnyRequest(): void
    {
        [$code, , $err] = $this->cli([$this->ics(), '52', $this->dir . '/nope/b.html']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Ordner für die Ausgabedatei', $err);
    }

    public function testAnAddressFromTheEnvironmentIsNotEchoed(): void
    {
        [$code, , $err] = $this->cli(['env:ENTERONCE_TEST_ICS', '52', $this->dir . '/b.html'], ['ENTERONCE_TEST_ICS' => 'http://intern.example/geheim-123.ics']);

        self::assertSame(2, $code);
        self::assertStringNotContainsString('geheim-123', $err);
    }

    public function testHelp(): void
    {
        [$code, $out] = $this->cli(['--help']);

        self::assertSame(0, $code);
        self::assertStringContainsString('--page-file=DATEI', $out);
        self::assertStringContainsString('höchstens einmal pro Stunde', $out);
    }
}
