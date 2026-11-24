<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use InvalidArgumentException;

/** Builds the address of the programme page from a base address and a `location_cat` id. */
final class PageAddress
{
    public const DEFAULT_BASE = 'https://www.qtp-basel.ch';
    public const DEFAULT_CENTRE = 'Quartierzentrum Union';
    public const PATH = '/programm/';

    /**
     * `https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union`
     *
     * @throws InvalidArgumentException
     */
    public static function build(string $base, int $categoryId, string $centreName): string
    {
        $parts = parse_url(rtrim(trim($base), '/'));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Die Basisadresse ist ungültig. Beispiel: https://www.qtp-basel.ch');
        }
        $loopback = in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        if ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && $loopback)) {
            throw new InvalidArgumentException('Die Basisadresse muss mit https:// beginnen.');
        }
        if ($categoryId < 1) {
            throw new InvalidArgumentException('Die location_cat-Nummer muss eine positive Zahl sein.');
        }
        $centreName = trim($centreName);
        if ($centreName === '') {
            throw new InvalidArgumentException('Der Name des Standorts darf nicht leer sein.');
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '') . self::PATH
            . '?location_cat[' . $categoryId . ']=' . rawurlencode($centreName);
    }

    /** The site's own list request must never be used (plan section 4.10). */
    public static function isForbidden(string $url): bool
    {
        return stripos($url, 'admin-ajax') !== false || stripos($url, 'filter_events') !== false;
    }
}
