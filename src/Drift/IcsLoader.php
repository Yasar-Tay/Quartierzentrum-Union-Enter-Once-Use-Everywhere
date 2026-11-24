<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/**
 * Gets the calendar text from a file path or an https address given by the operator (R-02).
 * An address is never printed or stored: it may be a secret (plan section 4.9).
 */
final class IcsLoader
{
    private const MAX_BYTES = 10_000_000;

    /** @var (callable(string): string)|null */
    private $download;
    /** @var (callable(string): list<string>)|null */
    private $resolve;

    /**
     * @param (callable(string): string)|null       $download returns the text of an address (tests inject one; default: a plain https GET)
     * @param (callable(string): list<string>)|null $resolve  returns the IP addresses of a host name (tests inject one; default: DNS)
     */
    public function __construct(?callable $download = null, ?callable $resolve = null)
    {
        $this->download = $download;
        $this->resolve = $resolve;
    }

    public static function isAddress(string $location): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1 || preg_match('#^webcal://#i', $location) === 1;
    }

    /** @throws IcsLoadError */
    public function load(string $location): string
    {
        if (!self::isAddress($location)) {
            $text = is_file($location) ? @file_get_contents($location) : false;
            if ($text === false) {
                throw new IcsLoadError('Die Kalenderdatei kann nicht gelesen werden: ' . $location);
            }

            return $text;
        }

        $url = (string) preg_replace('#^webcal://#i', 'https://', $location);
        $this->assertSafe($url);
        try {
            $text = $this->download !== null ? ($this->download)($url) : $this->get($url);
        } catch (IcsLoadError $e) {
            throw $e;
        } catch (\Throwable) {
            throw new IcsLoadError('Der Kalender konnte nicht heruntergeladen werden.');
        }

        return $text;
    }

    /**
     * R-02: https only, and no internal network addresses.
     *
     * @throws IcsLoadError
     */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            throw new IcsLoadError('Die Kalenderadresse muss mit https:// beginnen.');
        }
        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : ($this->resolve !== null ? ($this->resolve)($host) : $this->dns($host));
        if ($addresses === []) {
            throw new IcsLoadError('Die Kalenderadresse konnte nicht aufgelöst werden.');
        }
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new IcsLoadError('Die Kalenderadresse zeigt auf ein internes Netz und wird nicht abgerufen.');
            }
        }
    }

    /** @return list<string> */
    private function dns(string $host): array
    {
        $ips = @gethostbynamel($host);

        return $ips === false ? [] : array_values($ips);
    }

    private function get(string $url): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ' . HttpPageFetcher::USER_AGENT,
            'timeout' => 30,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);
        $text = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        if ($text === false) {
            throw new IcsLoadError('Der Kalender konnte nicht heruntergeladen werden.');
        }

        return $text;
    }
}
