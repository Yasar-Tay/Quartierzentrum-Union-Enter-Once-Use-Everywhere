<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * "Normalised title" for R-27 (draft reuse): cancel prefixes removed, lower case,
 * every run of characters that are not letters or digits replaced by one space,
 * trimmed. «Yoga im Union», «yoga im union!» and «Yoga  im Union» are equal;
 * «Yoga im Union (Frühling)» is not.
 */
final class TitleNormaliser
{
    /** @param list<string> $cancelPrefixes */
    public static function normalise(string $title, array $cancelPrefixes = []): string
    {
        [$clean] = CancelRule::apply($title, '', $cancelPrefixes);
        $clean = mb_strtolower($clean);
        $clean = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $clean);

        return trim($clean);
    }
}
