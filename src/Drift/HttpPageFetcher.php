<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/**
 * The real request: one plain GET of the programme page. Redirects are not followed (a changed
 * base address should be corrected by the operator), the answer is capped at 5 MB.
 * Never used in this project's tests; they inject a transport or a fake fetcher.
 */
final class HttpPageFetcher implements PageFetcher
{
    public const USER_AGENT = 'EnterOnceSync-DriftReport/1.0 (read-only comparison of one programme page; open source, GPL-2.0-or-later)';
    private const MAX_BYTES = 5_000_000;

    /** @var (callable(string, list<string>, int): array{int, string})|null */
    private $transport;

    /**
     * @param string                                                $contact   optional contact (an address or a URL) the operator adds to the User-Agent
     * @param (callable(string, list<string>, int): array{int, string})|null $transport for tests: returns [status, body]
     */
    public function __construct(private readonly string $contact = '', private readonly int $timeout = 20, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function userAgent(): string
    {
        $contact = trim((string) preg_replace('/[\r\n]+/', ' ', $this->contact));

        return $contact === '' ? self::USER_AGENT : self::USER_AGENT . ' contact: ' . $contact;
    }

    public function isLive(): bool
    {
        return true;
    }

    public function fetch(string $url): PageResponse
    {
        if (PageAddress::isForbidden($url)) {
            throw new FetchError('Die Listenabfrage der Website darf nicht verwendet werden.');
        }
        if (preg_match('#^https://#i', $url) !== 1 && preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])([:/]|$)#i', $url) !== 1) {
            throw new FetchError('Nur https-Adressen sind erlaubt.');
        }
        $headers = [
            'User-Agent: ' . $this->userAgent(),
            'Accept: text/html',
            'Accept-Language: de-CH,de;q=0.9',
        ];
        [$status, $body] = $this->transport !== null
            ? ($this->transport)($url, $headers, $this->timeout)
            : $this->get($url, $headers);

        return new PageResponse($status, $body);
    }

    /**
     * @param list<string> $headers
     *
     * @return array{int, string}
     */
    private function get(string $url, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $this->timeout,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);
        $http_response_header = [];
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($body === false || $status === 0) {
            throw new FetchError('Keine Antwort von der Website.');
        }

        return [$status, $body];
    }
}
