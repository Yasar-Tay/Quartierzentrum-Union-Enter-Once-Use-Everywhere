<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use EnterOnce\Drift\FetchError;
use EnterOnce\Drift\FilePageFetcher;
use EnterOnce\Drift\HttpPageFetcher;
use EnterOnce\Drift\IcsLoadError;
use EnterOnce\Drift\IcsLoader;
use EnterOnce\Drift\PageAddress;
use EnterOnce\Drift\PageResponse;
use InvalidArgumentException;

/** Address building, the HTTP fetcher with an injected transport (no socket), the file fetcher, the ICS loader. */
final class FetchingTest extends DriftTestCase
{
    public function testPageAddress(): void
    {
        self::assertSame(
            'https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union',
            PageAddress::build(PageAddress::DEFAULT_BASE, 52, PageAddress::DEFAULT_CENTRE)
        );
        self::assertSame('https://x.example/verband/programm/?location_cat[3]=A%20%26%20B', PageAddress::build('https://x.example/verband/', 3, 'A & B'));
        self::assertSame('http://127.0.0.1:8080/programm/?location_cat[1]=Test', PageAddress::build('http://127.0.0.1:8080', 1, 'Test'), 'plain http only for a local trial server');
    }

    /** @dataProvider badAddresses */
    public function testBadAddressesAreRefused(string $base, int $id, string $centre): void
    {
        $this->expectException(InvalidArgumentException::class);
        PageAddress::build($base, $id, $centre);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function badAddresses(): iterable
    {
        yield 'http on a public host' => ['http://www.qtp-basel.ch', 52, 'Union'];
        yield 'no scheme' => ['www.qtp-basel.ch', 52, 'Union'];
        yield 'query in the base' => ['https://www.qtp-basel.ch/?a=b', 52, 'Union'];
        yield 'zero id' => ['https://www.qtp-basel.ch', 0, 'Union'];
        yield 'empty name' => ['https://www.qtp-basel.ch', 52, '  '];
        yield 'ftp' => ['ftp://www.qtp-basel.ch', 52, 'Union'];
    }

    public function testTheSitesOwnListRequestIsForbidden(): void
    {
        self::assertTrue(PageAddress::isForbidden('https://www.qtp-basel.ch/wp-admin/admin-ajax.php'));
        self::assertTrue(PageAddress::isForbidden('https://www.qtp-basel.ch/programm/?action=filter_events'));
        self::assertFalse(PageAddress::isForbidden('https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union'));

        $calls = 0;
        $fetcher = new HttpPageFetcher('', 20, function () use (&$calls): array {
            $calls++;

            return [200, 'x'];
        });
        $this->expectException(FetchError::class);
        try {
            $fetcher->fetch('https://www.qtp-basel.ch/wp-admin/admin-ajax.php');
        } finally {
            self::assertSame(0, $calls, 'refused before any request');
        }
    }

    public function testTheRequestCarriesAUserAgentThatNamesTheProject(): void
    {
        $seen = null;
        $fetcher = new HttpPageFetcher('', 20, function (string $url, array $headers, int $timeout) use (&$seen): array {
            $seen = [$url, $headers, $timeout];

            return [200, '<html></html>'];
        });
        $response = $fetcher->fetch('https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union');

        self::assertSame(200, $response->status);
        self::assertNotNull($seen);
        $userAgent = array_values(array_filter($seen[1], static fn (string $h) => str_starts_with($h, 'User-Agent:')));
        self::assertCount(1, $userAgent);
        self::assertStringContainsString('EnterOnceSync-DriftReport', $userAgent[0]);
        self::assertStringContainsString('read-only', $userAgent[0]);
        self::assertSame([], array_filter($seen[1], static fn (string $h) => stripos($h, 'cookie') === 0 || stripos($h, 'authorization') === 0), 'no credentials');
        self::assertSame(20, $seen[2]);
        self::assertTrue($fetcher->isLive());
    }

    public function testTheOperatorMayAddAContactToTheUserAgent(): void
    {
        $f = new HttpPageFetcher("kontakt@example.invalid\r\nX-Evil: 1");
        self::assertStringContainsString('contact: kontakt@example.invalid X-Evil: 1', $f->userAgent());
        self::assertStringNotContainsString("\n", $f->userAgent(), 'no header injection');
    }

    public function testOnlyHttpsAddressesAreRequested(): void
    {
        $fetcher = new HttpPageFetcher('', 20, fn (): array => [200, '']);
        $this->expectException(FetchError::class);
        $fetcher->fetch('http://www.qtp-basel.ch/programm/');
    }

    public function testResponseClassification(): void
    {
        self::assertTrue((new PageResponse(403, ''))->isBlock());
        self::assertTrue((new PageResponse(429, ''))->isBlock());
        self::assertFalse((new PageResponse(404, ''))->isBlock());
        self::assertTrue((new PageResponse(204, ''))->isSuccess());
        self::assertFalse((new PageResponse(301, ''))->isSuccess());
    }

    public function testFileFetcherReadsAFileAndIsNotLive(): void
    {
        $f = new FilePageFetcher(self::fixturePath('programm-maintenance.html'));
        self::assertFalse($f->isLive());
        self::assertStringContainsString('Wartungsarbeiten', $f->fetch('https://ignored.example/')->body);

        $this->expectException(FetchError::class);
        (new FilePageFetcher(self::fixturePath('gibt-es-nicht.html')))->fetch('x');
    }

    /* ---------- ICS loader (R-02) ---------- */

    public function testIcsFromAFile(): void
    {
        self::assertStringStartsWith('BEGIN:VCALENDAR', (new IcsLoader())->load(self::fixturePath('drift-calendar.ics')));
        $this->expectException(IcsLoadError::class);
        (new IcsLoader())->load(self::fixturePath('gibt-es-nicht.ics'));
    }

    /** @dataProvider unsafeAddresses */
    public function testIcsAddressesMustBeHttpsAndPublic(string $address): void
    {
        $downloads = 0;
        $loader = new IcsLoader(function () use (&$downloads): string {
            $downloads++;

            return '';
        }, fn (string $host): array => $host === 'intern.example' ? ['10.0.0.5'] : ['93.184.216.34']);
        try {
            $loader->load($address);
            self::fail('IcsLoadError expected');
        } catch (IcsLoadError $e) {
            self::assertStringNotContainsString($address, $e->getMessage(), 'the address is not echoed');
        }
        self::assertSame(0, $downloads);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeAddresses(): iterable
    {
        yield 'http' => ['http://calendar.example.org/a.ics'];
        yield 'ftp' => ['ftp://calendar.example.org/a.ics'];
        yield 'loopback ip' => ['https://127.0.0.1/a.ics'];
        yield 'private ip' => ['https://192.168.1.20/a.ics'];
        yield 'link-local ip' => ['https://169.254.169.254/latest/meta-data'];
        yield 'ipv6 loopback' => ['https://[::1]/a.ics'];
        yield 'host that resolves to a private address' => ['https://intern.example/a.ics'];
    }

    public function testPublicHttpsAndWebcalAddressesAreDownloadedThroughTheInjectedDownloader(): void
    {
        $urls = [];
        $loader = new IcsLoader(function (string $url) use (&$urls): string {
            $urls[] = $url;

            return 'BEGIN:VCALENDAR';
        }, fn (): array => ['93.184.216.34']);

        self::assertSame('BEGIN:VCALENDAR', $loader->load('https://calendar.example.org/a.ics'));
        self::assertSame('BEGIN:VCALENDAR', $loader->load('webcal://calendar.example.org/b.ics'));
        self::assertSame(['https://calendar.example.org/a.ics', 'https://calendar.example.org/b.ics'], $urls);
    }

    public function testADownloadFailureDoesNotLeakTheAddress(): void
    {
        $loader = new IcsLoader(function (): string {
            throw new \RuntimeException('connect to https://calendar.example.org/secret failed');
        }, fn (): array => ['93.184.216.34']);
        try {
            $loader->load('https://calendar.example.org/secret');
            self::fail('IcsLoadError expected');
        } catch (IcsLoadError $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }
}
