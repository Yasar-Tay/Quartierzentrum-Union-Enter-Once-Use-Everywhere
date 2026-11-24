<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * R-02: https only, through WordPress's safe HTTP function, which refuses internal network
 * addresses (`reject_unsafe_urls`). R-03 (download part): any failure is an error.
 *
 * Review D8: WordPress follows no redirect by itself here. A redirect is followed by this class,
 * at most MAX_REDIRECTS times, and every target is checked again (https, public address).
 * A body larger than MAX_BYTES is refused, not truncated.
 */
final class HttpFetcher implements Fetcher
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_REDIRECTS = 3;

    /** @var callable(string): bool */
    private $isPublicUrl;

    /**
     * @param (callable(string): bool)|null $isPublicUrl address check; default wp_http_validate_url()
     *                                                   (which refuses private and local addresses)
     */
    public function __construct(?callable $isPublicUrl = null)
    {
        $this->isPublicUrl = $isPublicUrl ?? static fn (string $url): bool => (bool) wp_http_validate_url($url);
    }

    public function fetch(string $url): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $this->assertAllowedUrl($url);
            $response = wp_safe_remote_get($url, [
                'timeout' => 20,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'limit_response_size' => self::MAX_BYTES + 1,
                'user-agent' => 'EnterOnceSync/' . (defined('ENTERONCE_VERSION') ? ENTERONCE_VERSION : 'dev') . ' (WordPress)',
                'headers' => ['Accept' => 'text/calendar, text/plain;q=0.8, */*;q=0.1'],
            ]);
            if (is_wp_error($response)) {
                throw new FetchError(sprintf(
                    /* translators: %s: technical reason */
                    __('Der Kalender konnte nicht heruntergeladen werden (%s).', 'enter-once-sync'),
                    Secret::scrub($response->get_error_message(), $url)
                ));
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 300 && $code < 400) {
                $location = (string) wp_remote_retrieve_header($response, 'location');
                if ($location === '') {
                    break;
                }
                $url = \WP_Http::make_absolute_url($location, $url);
                continue;
            }
            if ($code !== 200) {
                throw new FetchError(sprintf(
                    /* translators: %d: HTTP status code */
                    __('Der Kalender-Server hat mit Status %d geantwortet.', 'enter-once-sync'),
                    $code
                ));
            }
            $length = (int) wp_remote_retrieve_header($response, 'content-length');
            $body = (string) wp_remote_retrieve_body($response);
            if ($length > self::MAX_BYTES || strlen($body) > self::MAX_BYTES) {
                throw new FetchError(sprintf(__('Die Kalenderdatei ist grösser als %d MB und wird nicht gelesen.', 'enter-once-sync'), (int) (self::MAX_BYTES / 1024 / 1024)));
            }

            return $body;
        }

        throw new FetchError(__('Der Kalender-Server leitet zu oft oder ohne Ziel weiter.', 'enter-once-sync'));
    }

    /** @throws FetchError */
    private function assertAllowedUrl(string $url): void
    {
        self::assertAllowed($url, $this->isPublicUrl);
    }

    /**
     * @param (callable(string): bool)|null $isPublicUrl
     * @throws FetchError
     */
    public static function assertAllowed(string $url, ?callable $isPublicUrl = null): void
    {
        if ($url === '') {
            throw new FetchError(__('Für dieses Zentrum ist keine Kalender-Adresse (ICS) eingetragen.', 'enter-once-sync'));
        }
        $check = $isPublicUrl ?? static fn (string $u): bool => (bool) wp_http_validate_url($u);
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https' || !$check($url)) {
            throw new FetchError(__('Die Kalender-Adresse (ICS) muss mit https:// beginnen und darf nicht auf ein internes Netz zeigen.', 'enter-once-sync'));
        }
    }
}
