<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use RuntimeException;

/**
 * The programme page does not look the way the reader expects. The report must stop
 * with this message and never present "nothing found" as "the site is empty".
 */
final class PageStructureError extends RuntimeException
{
    public const REASON_EMPTY = 'empty_page';
    public const REASON_NO_CONTAINER = 'no_container';
    public const REASON_ITEMS_NOT_READABLE = 'items_not_readable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function germanMessage(): string
    {
        return 'Die Struktur der Programmseite wurde nicht erkannt. Die Seite wurde möglicherweise umgebaut. '
            . 'Es wird kein Bericht erstellt, damit eine leere Liste nicht mit einer leeren Website verwechselt wird. '
            . '(' . $this->getMessage() . ')';
    }
}
