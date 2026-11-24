<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * The public feed of section 4.8 / AGENTS.md, built from stored occurrences.
 *
 * Cancelled occurrences are left out when `cancel_mode` is `unpublish` (the default)
 * and included with `cancelled: true` when it is `badge`. `image` is always null here;
 * the WordPress shell resolves images (A-15) and may replace it.
 */
final class Feed
{
    /**
     * @param list<Occurrence> $occurrences
     * @param Window|null $range the request's from/to (local days); null = everything given
     * @return list<array<string, mixed>> sorted by start, then id
     */
    public static function build(array $occurrences, Settings $settings, ?Window $range = null): array
    {
        $items = [];
        foreach ($occurrences as $o) {
            if ($o->cancelled && $settings->cancelMode !== 'badge') {
                continue;
            }
            if ($range !== null && !$range->overlaps($o->start, $o->end)) {
                continue;
            }
            $items[] = [
                'id' => $o->syncKey,
                'title' => $o->title,
                'start' => $o->start->format(DATE_ATOM),
                'end' => $o->end->format(DATE_ATOM),
                'all_day' => $o->allDay,
                'room' => $o->room,
                'location' => $o->location,
                'description_html' => $o->descriptionHtml,
                'categories' => $o->categories,
                'tags' => $o->tags,
                'image' => null,
                'cancelled' => $o->cancelled,
                'series_id' => $o->seriesKey(),
                '_ts' => $o->start->getTimestamp(),
            ];
        }
        usort($items, static fn (array $a, array $b) => [$a['_ts'], $a['id']] <=> [$b['_ts'], $b['id']]);

        return array_map(static function (array $i): array {
            unset($i['_ts']);

            return $i;
        }, $items);
    }
}
