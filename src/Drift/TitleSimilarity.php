<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/**
 * How alike two titles are, 0.0 to 1.0. Used only to tell candidates apart or to pair events whose
 * times differ; a title is never the key (plan section 4.10).
 *
 * Titles differ between the calendar and the site («Café Balance» / «Café Bâlance»,
 * «Deutsch im Alltag» / «Deutsch im Alltag A», «Yoga im Union» / «Yoga am Montag (Start 09:00 Uhr)»),
 * so the comparison folds accents and case, drops a leading «Test:», filler words, weekday names,
 * the word «Union» and bare numbers, and takes the best of three measures:
 *   - Dice coefficient of the remaining words,
 *   - 0.9 × containment (all words of the shorter title appear in the longer),
 *   - edit-distance ratio of the folded texts.
 */
final class TitleSimilarity
{
    private const STOPWORDS = [
        'am', 'im', 'in', 'der', 'die', 'das', 'den', 'dem', 'und', 'fur', 'fuer', 'mit', 'von', 'zum', 'zur',
        'ein', 'eine', 'uhr', 'start', 'union', 'jeden', 'ab',
        'montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag', 'samstag', 'sonntag',
    ];

    public static function score(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        $fa = implode(' ', $ta);
        $fb = implode(' ', $tb);
        if ($ta === [] || $tb === []) {
            $fa = self::fold($a);
            $fb = self::fold($b);

            return self::editRatio($fa, $fb);
        }

        $common = count(array_intersect($ta, $tb));
        $dice = 2 * $common / (count($ta) + count($tb));
        $containment = $common / min(count($ta), count($tb));

        return max($dice, 0.9 * $containment, self::editRatio($fa, $fb));
    }

    /** @return list<string> distinct significant words, folded */
    public static function tokens(string $title): array
    {
        $title = (string) preg_replace('/^\s*test\s*:\s*/iu', '', $title);
        $folded = self::fold($title);
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (ctype_digit($w) || in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            $out[$w] = $w;
        }

        return array_values($out);
    }

    /** Lower case, accents and umlauts removed, ß → ss. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s);

        return strtr($s, [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    private static function editRatio(string $a, string $b): float
    {
        $a = substr($a, 0, 200);
        $b = substr($b, 0, 200);
        $max = max(strlen($a), strlen($b));
        if ($max === 0) {
            return 1.0;
        }

        return max(0.0, 1 - levenshtein($a, $b) / $max);
    }
}
