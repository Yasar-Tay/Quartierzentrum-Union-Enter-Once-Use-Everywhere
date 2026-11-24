<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * Plan 4.9 "sanitised before storage": single-line fields (title, room, location) are plain text.
 * HTML tags are removed, entities decoded once, control characters dropped, whitespace collapsed.
 * A "<" that does not start a tag ("Kinder <3") is kept as text.
 */
final class PlainText
{
    public static function line(string $value): string
    {
        // Tags, comments and the contents of script/style elements.
        $value = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', ' ', $value);
        $value = (string) preg_replace('/<!--.*?-->/s', ' ', $value);
        $value = (string) preg_replace('/<\/?[a-zA-Z][^>]*>?/', ' ', $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        $value = (string) preg_replace('/\s+/u', ' ', $value);

        // A removed tag right before punctuation leaves no gap: "<b>Saal</b>, Basel".
        return trim((string) preg_replace('/ ([,.;:!?)])/u', '$1', $value));
    }
}
