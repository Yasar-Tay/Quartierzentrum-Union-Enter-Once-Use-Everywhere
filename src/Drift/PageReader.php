<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads the HTML of the association's programme page into dated events and weekly offers.
 *
 * The markup is reconstructed from the site's own list script (docs/dev/programme-page-notes.md),
 * never recorded from the page, so the reader is tolerant (whitespace, attribute order, entities,
 * extra classes) and strict about one thing: when it cannot find what it expects it throws
 * PageStructureError instead of returning an empty list.
 *
 * Expected structure:
 *   .events-simple-programme   dated events, items `.qtp-article-preview`
 *   .events-listing-weekly     weekly offers, same item markup
 *   item: h3 (title, inside the link), `.qtp-article-date` spans (day or weekday, time),
 *         optional `.qtp-article-cancelled` band (abgesagt / Ferienpause, class `qtp-vacation`)
 *   `.qtp-events-load-more[data-events=normal|weekly]` buttons, `form.filter-form[data-perpage]`
 */
final class PageReader
{
    private const WEEKDAYS = [
        'montag' => 1, 'dienstag' => 2, 'mittwoch' => 3, 'donnerstag' => 4,
        'freitag' => 5, 'samstag' => 6, 'sonntag' => 7,
    ];

    /**
     * @param string|null $baseUrl address of the page; relative links are resolved against its origin
     *
     * @throws PageStructureError
     */
    public function read(string $html, ?string $baseUrl = null): PageSnapshot
    {
        if (trim($html) === '') {
            throw new PageStructureError(PageStructureError::REASON_EMPTY, 'Die Antwort ist leer.');
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xp = new DOMXPath($doc);

        $datedBlocks = $this->nodes($xp, '//*[' . self::cls('events-simple-programme') . ']');
        $weeklyBlocks = $this->nodes($xp, '//*[' . self::cls('events-listing-weekly') . ']');
        if ($datedBlocks === [] && $weeklyBlocks === []) {
            throw new PageStructureError(
                PageStructureError::REASON_NO_CONTAINER,
                'Weder «events-simple-programme» noch «events-listing-weekly» gefunden.'
            );
        }

        $origin = $this->origin($baseUrl);
        $unreadable = [];
        $weekly = [];
        $dated = [];

        foreach ($weeklyBlocks as $block) {
            $before = count($weekly) + count($unreadable);
            foreach ($this->items($xp, $block) as $item) {
                $event = $this->parseItem($xp, $item, PageEvent::WEEKLY, count($weekly), $origin);
                if ($event === null) {
                    $unreadable[] = $this->describe($xp, $item);
                } else {
                    $weekly[] = $event;
                }
            }
            $this->assertNotSilentlyEmpty($xp, $block, count($weekly) + count($unreadable) - $before, 'wöchentliche Angebote');
        }
        foreach ($datedBlocks as $block) {
            $before = count($dated) + count($unreadable);
            foreach ($this->items($xp, $block) as $item) {
                if ($this->insideAny($item, $weeklyBlocks)) {
                    continue;
                }
                $event = $this->parseItem($xp, $item, PageEvent::DATED, count($dated), $origin);
                if ($event === null) {
                    $unreadable[] = $this->describe($xp, $item);
                } else {
                    $dated[] = $event;
                }
            }
            $this->assertNotSilentlyEmpty($xp, $block, count($dated) + count($unreadable) - $before, 'Veranstaltungen');
        }

        if ($weekly === [] && $dated === [] && $unreadable !== []) {
            throw new PageStructureError(
                PageStructureError::REASON_ITEMS_NOT_READABLE,
                'Einträge gefunden, aber keiner liess sich lesen (Titel, Datum oder Wochentag fehlen).'
            );
        }

        $perPage = 8;
        $form = $this->nodes($xp, '//form[' . self::cls('filter-form') . '][@data-perpage]');
        if ($form !== [] && ctype_digit(trim($form[0]->getAttribute('data-perpage')))) {
            $perPage = max(1, (int) trim($form[0]->getAttribute('data-perpage')));
        }

        return new PageSnapshot(
            $dated,
            $weekly,
            $unreadable,
            $this->nodes($xp, '//*[' . self::cls('qtp-events-load-more') . '][@data-events="normal"]') !== [],
            $this->nodes($xp, '//*[' . self::cls('qtp-events-load-more') . '][@data-events="weekly"]') !== [],
            $perPage,
            $datedBlocks !== [],
            $weeklyBlocks !== [],
        );
    }

    /** XPath test for one class token. */
    private static function cls(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
    }

    /** @return list<DOMElement> */
    private function nodes(DOMXPath $xp, string $query, ?DOMNode $context = null): array
    {
        $list = $context === null ? $xp->query($query) : $xp->query($query, $context);
        $out = [];
        if ($list !== false) {
            foreach ($list as $node) {
                if ($node instanceof DOMElement) {
                    $out[] = $node;
                }
            }
        }

        return $out;
    }

    /** @return list<DOMElement> */
    private function items(DOMXPath $xp, DOMElement $block): array
    {
        return $this->nodes($xp, './/*[' . self::cls('qtp-article-preview') . ']', $block);
    }

    /** @param list<DOMElement> $blocks */
    private function insideAny(DOMElement $node, array $blocks): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            foreach ($blocks as $block) {
                if ($p === $block) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A block that holds links, headings or pictures but yielded no item means the item markup
     * changed: stop instead of reporting an empty list.
     */
    private function assertNotSilentlyEmpty(DOMXPath $xp, DOMElement $block, int $itemsInBlock, string $what): void
    {
        if ($itemsInBlock > 0) {
            return;
        }
        if ($this->nodes($xp, './/a[@href] | .//h3 | .//img | .//article', $block) !== []) {
            throw new PageStructureError(
                PageStructureError::REASON_NO_CONTAINER,
                "Der Bereich für $what enthält Inhalt, aber keinen bekannten Eintrag («qtp-article-preview»)."
            );
        }
    }

    private function parseItem(DOMXPath $xp, DOMElement $item, string $kind, int $index, ?string $origin): ?PageEvent
    {
        $h3 = $this->nodes($xp, './/h3', $item)[0] ?? null;
        $title = $h3 === null ? '' : $this->text($h3->textContent);
        if ($title === '') {
            return null;
        }

        $dateText = '';
        foreach ($this->nodes($xp, './/*[' . self::cls('qtp-article-date') . ']', $item) as $span) {
            $dateText .= ' ' . $this->text($span->textContent);
        }

        $date = null;
        $endDate = null;
        $weekday = null;
        if (preg_match_all('/(?<!\d)(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?!\d)/', $dateText, $m, PREG_SET_ORDER) > 0) {
            $date = $this->ymd((int) $m[0][3], (int) $m[0][2], (int) $m[0][1]);
            if (isset($m[1])) {
                $endDate = $this->ymd((int) $m[1][3], (int) $m[1][2], (int) $m[1][1]);
            }
            $dateText = (string) preg_replace('/(?<!\d)(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?!\d)/', ' ', $dateText);
        }
        if (preg_match('/(montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag)s?(?![\p{L}])/iu', $dateText, $w) === 1) {
            $weekday = self::WEEKDAYS[mb_strtolower($w[1])];
        }
        if ($kind === PageEvent::DATED && $date === null) {
            return null;
        }
        if ($kind === PageEvent::WEEKLY && $weekday === null) {
            return null;
        }

        $times = [];
        if (preg_match_all('/(?<!\d)(\d{1,2})\s*[:.]\s*(\d{2})(?!\d)/', $dateText, $t, PREG_SET_ORDER) > 0) {
            foreach ($t as $match) {
                if ((int) $match[1] <= 24 && (int) $match[2] < 60) {
                    $times[] = sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
                }
            }
        }

        $cancelled = false;
        $vacation = false;
        foreach ($this->nodes($xp, './/*[' . self::cls('qtp-article-cancelled') . ']', $item) as $band) {
            $words = mb_strtolower($this->text($band->textContent));
            $classes = ' ' . preg_replace('/\s+/', ' ', $band->getAttribute('class')) . ' ';
            if (str_contains($words, 'ferienpause') || str_contains($classes, ' qtp-vacation ')) {
                $vacation = true;
            } else {
                $cancelled = true;
            }
        }

        return new PageEvent(
            $kind,
            $title,
            $this->link($xp, $item, $h3, $origin),
            $date,
            $endDate,
            $weekday,
            $times[0] ?? null,
            $times[1] ?? null,
            $cancelled,
            $vacation,
            $index,
        );
    }

    private function link(DOMXPath $xp, DOMElement $item, ?DOMElement $h3, ?string $origin): ?string
    {
        $anchor = $h3 !== null ? ($this->nodes($xp, 'ancestor::a[@href][1]', $h3)[0] ?? null) : null;
        $anchor ??= $this->nodes($xp, './/a[' . self::cls('qtp-read-more') . '][@href]', $item)[0] ?? null;
        $anchor ??= $this->nodes($xp, './/a[@href]', $item)[0] ?? null;
        if ($anchor === null) {
            return null;
        }
        $href = trim($anchor->getAttribute('href'));
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, '/') && !str_starts_with($href, '//') && $origin !== null) {
            $href = $origin . $href;
        }

        return preg_match('#^https?://#i', $href) === 1 ? $href : null;
    }

    private function origin(?string $baseUrl): ?string
    {
        if ($baseUrl === null) {
            return null;
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function describe(DOMXPath $xp, DOMElement $item): string
    {
        $h3 = $this->nodes($xp, './/h3', $item)[0] ?? null;
        $title = $h3 === null ? '' : $this->text($h3->textContent);

        return $title !== '' ? $title : '(Eintrag ohne Titel)';
    }

    private function ymd(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    /** Collapses whitespace, including non-breaking spaces. */
    private function text(string $s): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
    }
}
