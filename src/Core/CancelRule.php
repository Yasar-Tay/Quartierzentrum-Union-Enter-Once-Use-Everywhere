<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * A-09: an entry is cancelled if STATUS is CANCELLED or its title starts with one of
 * `cancel_prefixes` (case-insensitive). The prefix is removed from the title.
 */
final class CancelRule
{
    /**
     * @param list<string> $prefixes
     * @return array{0: string, 1: bool} cleaned title, cancelled
     */
    public static function apply(string $summary, string $status, array $prefixes): array
    {
        $title = trim($summary);
        $cancelled = strtoupper(trim($status)) === 'CANCELLED';
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($prefixes as $prefix) {
                $prefix = trim($prefix);
                if ($prefix !== '' && mb_stripos($title, $prefix) === 0) {
                    $title = trim(mb_substr($title, mb_strlen($prefix)));
                    $cancelled = true;
                    $changed = true;
                }
            }
        }

        return [$title, $cancelled];
    }
}
