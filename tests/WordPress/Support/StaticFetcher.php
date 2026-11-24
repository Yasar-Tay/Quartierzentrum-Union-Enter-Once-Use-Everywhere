<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress\Support;

use EnterOnce\WordPress\Fetcher;
use EnterOnce\WordPress\FetchError;

/** Returns prepared texts per address. For tests only (not shipped): no network. */
final class StaticFetcher implements Fetcher
{
    /** @var array<string, string|FetchError> */
    private array $responses = [];

    /** @var list<string> */
    public array $requested = [];

    public function set(string $url, string|FetchError $response): self
    {
        $this->responses[$url] = $response;

        return $this;
    }

    public function setFile(string $url, string $path): self
    {
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException("Cannot read $path");
        }

        return $this->set($url, $text);
    }

    public function fetch(string $url): string
    {
        $this->requested[] = $url;
        // Same https rule as HttpFetcher, without the DNS lookup of wp_http_validate_url().
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new FetchError(__('Die Kalender-Adresse (ICS) muss mit https:// beginnen und darf nicht auf ein internes Netz zeigen.', 'enter-once-sync'));
        }
        $r = $this->responses[$url] ?? new FetchError(__('Der Kalender konnte nicht heruntergeladen werden (keine Antwort).', 'enter-once-sync'));
        if ($r instanceof FetchError) {
            throw $r;
        }

        return $r;
    }
}
