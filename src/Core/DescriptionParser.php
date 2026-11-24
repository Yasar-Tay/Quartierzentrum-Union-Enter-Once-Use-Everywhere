<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * Description clean-up and the tolerant `[event-type]` / `[tag]` block parser.
 *
 * Tolerated: unclosed blocks, blank lines, spaces around `=` (`name= Bildung`),
 * blocks in any order, several `name=` lines per block, several blocks of one kind,
 * upper/lower case in the block markers.
 *
 * A block ends at its closing marker, at the next opening marker, at the first
 * non-empty line that is not a `name=` line, or at the end of the text.
 *
 * The cleaned text keeps everything outside the blocks. `<mailto:…>`, `<https://…>`,
 * `<http://…>` and `<tel:…>` remnants (Outlook's plain-text link rendering) are removed.
 * If the description contains HTML it is reduced to plain text first. The HTML
 * output contains only `<p>` and `<br>` around escaped text, so it is safe by
 * construction.
 */
final class DescriptionParser
{
    /**
     * @return array{text: string, html: string, categories: list<string>, tags: list<string>}
     *         categories and tags are the raw values as written, before mapping
     */
    public static function parse(string $description): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $description);
        $text = (string) preg_replace('/<(?:mailto|https?|tel):[^>\s]*>/i', '', $text);
        if (self::looksLikeHtml($text)) {
            $text = self::htmlToText($text);
        }

        $tokens = preg_split('/(\[\s*\/?\s*(?:event-type|tag)\s*\])/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $body = '';
        $state = null;
        $values = ['event-type' => [], 'tag' => []];
        foreach ($tokens === false ? [$text] : $tokens as $token) {
            if (preg_match('/^\[\s*(\/?)\s*(event-type|tag)\s*\]$/i', $token, $m)) {
                $kind = strtolower($m[2]);
                if ($m[1] === '') {
                    $state = $kind;
                } elseif ($state === $kind) {
                    $state = null;
                }
                // A stray closing marker is dropped.
                continue;
            }
            if ($state === null) {
                $body .= $token;
                continue;
            }
            $lines = explode("\n", $token);
            foreach ($lines as $i => $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (preg_match('/^\s*name\s*=\s*(.*?)\s*$/iu', $line, $nm)) {
                    $value = trim((string) preg_replace('/\s+/u', ' ', $nm[1]));
                    if ($value !== '' && !in_array($value, $values[$state], true)) {
                        $values[$state][] = $value;
                    }
                    continue;
                }
                // Not a name= line: the (unclosed) block ended before this line.
                $state = null;
                $body .= implode("\n", array_slice($lines, $i));
                break;
            }
        }

        $clean = self::tidy($body);

        return [
            'text' => $clean,
            'html' => self::textToHtml($clean),
            'categories' => $values['event-type'],
            'tags' => $values['tag'],
        ];
    }

    public static function textToHtml(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $lines = array_map(
                static fn (string $l) => htmlspecialchars($l, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                explode("\n", $paragraph)
            );
            $html .= '<p>' . implode('<br>', $lines) . '</p>';
        }

        return $html;
    }

    private static function tidy(string $text): string
    {
        $lines = array_map(static fn (string $l) => rtrim($l), explode("\n", $text));
        $text = implode("\n", $lines);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function looksLikeHtml(string $text): bool
    {
        return (bool) preg_match('/<\s*(br|p|div|span|a|b|i|strong|em|ul|ol|li|html|body)\b[^>]*>|<\/\s*(p|div|span|a|b|i|strong|em|ul|ol|li)\s*>|&nbsp;/i', $text);
    }

    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
        $text = (string) preg_replace('/<\/\s*(p|div|li|ul|ol|h[1-6])\s*>/i', "\n\n", $text);
        $text = (string) preg_replace('/<\s*(script|style)\b[^>]*>.*?<\/\s*\1\s*>/is', '', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{00A0}", ' ', $text);
    }
}
