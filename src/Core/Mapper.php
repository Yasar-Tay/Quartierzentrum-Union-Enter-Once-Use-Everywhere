<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * Resolves calendar categories and tags (section 4.5) and maps them to the
 * association's terms.
 *
 * Resolution of one raw value, in this order:
 *  1. exact match with a key of `category_map` / `tag_map`, ignoring case and
 *     surrounding or repeated spaces: accepted silently;
 *  2. synonym table (`synonyms`, same comparison): accepted silently, because
 *     the synonym was configured on purpose;
 *  3. spelling tolerance: Levenshtein distance ≤ 1 (counted in characters, not
 *     bytes, case-insensitive) to exactly one key: corrected, warning;
 *  4. otherwise dropped, warning. No value is invented.
 */
final class Mapper
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param list<string> $raw
     * @return array{values: list<string>, issues: list<array{kind: string, value: string, corrected_to: ?string}>}
     *         values are canonical calendar names (keys of the map); issues are 'corrected' or 'dropped'
     */
    public function resolve(array $raw, string $field): array
    {
        $map = $field === 'tag' ? $this->settings->tagMap : $this->settings->categoryMap;
        $keys = array_keys($map);
        $byNorm = [];
        foreach ($keys as $key) {
            $byNorm[self::norm((string) $key)] = (string) $key;
        }
        $synonyms = [];
        foreach ($this->settings->synonyms as $variant => $canonical) {
            $synonyms[self::norm((string) $variant)] = $canonical;
        }

        $values = [];
        $issues = [];
        foreach ($raw as $value) {
            $n = self::norm($value);
            if ($n === '') {
                continue;
            }
            $resolved = null;
            if (isset($byNorm[$n])) {
                $resolved = $byNorm[$n];
            } elseif (isset($synonyms[$n]) && isset($byNorm[self::norm($synonyms[$n])])) {
                $resolved = $byNorm[self::norm($synonyms[$n])];
            } else {
                $candidates = [];
                foreach ($byNorm as $keyNorm => $key) {
                    if (self::distanceAtMostOne($n, (string) $keyNorm)) {
                        $candidates[] = $key;
                    }
                }
                if (count($candidates) === 1) {
                    $resolved = $candidates[0];
                    $issues[] = ['kind' => 'corrected', 'value' => $value, 'corrected_to' => $resolved];
                } else {
                    $issues[] = ['kind' => 'dropped', 'value' => $value, 'corrected_to' => null];
                }
            }
            if ($resolved !== null && !in_array($resolved, $values, true)) {
                $values[] = $resolved;
            }
        }

        return ['values' => $values, 'issues' => $issues];
    }

    /**
     * Association topic names for canonical calendar categories (deduplicated, in order).
     *
     * @param list<string> $categories
     * @return list<string>
     */
    public function topics(array $categories): array
    {
        return self::mapValues($categories, $this->settings->categoryMap);
    }

    /**
     * Association target-group names for canonical calendar tags.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public function targetGroups(array $tags): array
    {
        return self::mapValues($tags, $this->settings->tagMap);
    }

    public static function norm(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /** Levenshtein distance ≤ 1 on characters (UTF-8 aware). */
    public static function distanceAtMostOne(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $lx = count($x);
        $ly = count($y);
        if (abs($lx - $ly) > 1) {
            return false;
        }
        if ($lx > $ly) {
            [$x, $y, $lx, $ly] = [$y, $x, $ly, $lx];
        }
        $i = 0;
        while ($i < $lx && $x[$i] === $y[$i]) {
            ++$i;
        }
        if ($lx === $ly) {
            return array_slice($x, $i + 1) === array_slice($y, $i + 1);
        }

        return array_slice($x, $i) === array_slice($y, $i + 1);
    }

    /**
     * @param list<string> $values
     * @param array<string, list<string>|null> $map
     * @return list<string>
     */
    private static function mapValues(array $values, array $map): array
    {
        $out = [];
        foreach ($values as $value) {
            foreach ($map[$value] ?? [] as $target) {
                if (!in_array($target, $out, true)) {
                    $out[] = $target;
                }
            }
        }

        return $out;
    }
}
