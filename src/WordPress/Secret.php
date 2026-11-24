<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * The ICS address is a secret (plan 4.9): stored only in the settings, shown in full only to
 * administrators on the settings page, never logged, never mailed.
 */
final class Secret
{
    /** «https://calendar.example.org/…» – scheme and host only. */
    public static function mask(string $url): string
    {
        if ($url === '') {
            return '–';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '…';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/…';
    }

    /** Removes the address (and its path) from a message before it is shown, mailed or stored. */
    public static function scrub(string $message, string $url): string
    {
        if ($url === '') {
            return $message;
        }
        $message = str_replace($url, self::mask($url), $message);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (strlen($path) > 4) {
            $message = str_replace($path, '/…', $message);
        }

        return $message;
    }
}
