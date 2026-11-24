<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/**
 * Turns candidate pairs into matches without guessing.
 *
 * Input: scores[l][r] for every candidate pair (left = calendar side, right = site side);
 * a higher score is a better pair. The candidates are connected groups; in each group:
 *   1. a pair where both sides have no other candidate is a match;
 *   2. otherwise a pair is accepted only if its score is at least STRONG and beats every other
 *      candidate of both of its members by at least MARGIN;
 *   3. matched members leave the group and the rest is looked at again;
 *   4. when nothing is clear any more, candidates far weaker than the best pair of their group are
 *      dropped (WEAK_GAP) and the steps repeat;
 *   5. what remains with candidates left is AMBIGUOUS: reported as such, never paired.
 */
final class Resolver
{
    /** A pair needs this score to be accepted among several candidates. */
    public const STRONG = 0.70;
    /** …and must lead the next best candidate of either member by this much. */
    public const MARGIN = 0.15;
    /** Left-over candidates weaker than the best pair of their group by more than this are dropped. */
    public const WEAK_GAP = 0.35;

    /**
     * @param array<int, array<int, float>> $scores
     *
     * @return array{matches: list<array{int, int}>, ambiguous: list<array{left: list<int>, right: list<int>}>}
     */
    public static function resolve(array $scores): array
    {
        $pairs = [];
        foreach ($scores as $l => $row) {
            foreach ($row as $r => $score) {
                $pairs[] = [$l, $r, $score];
            }
        }
        $matches = [];

        while (true) {
            $changed = false;
            foreach (self::components($pairs) as $component) {
                $accepted = self::acceptOne($component);
                if ($accepted !== null) {
                    $matches[] = [$accepted[0], $accepted[1]];
                    $pairs = array_values(array_filter(
                        $pairs,
                        static fn (array $p) => $p[0] !== $accepted[0] && $p[1] !== $accepted[1]
                    ));
                    $changed = true;
                    break; // the groups changed: look again
                }
            }
            if ($changed) {
                continue;
            }
            // Nothing clear is left. Drop candidates that are far weaker than the best pair of their
            // group (a site offer that merely overlaps in time), so they do not pad an ambiguous
            // group; a pair that is then unique is accepted in the next round.
            $pruned = false;
            foreach (self::components($pairs) as $component) {
                $top = max(array_map(static fn (array $p) => $p[2], $component));
                foreach ($component as $pair) {
                    if ($pair[2] < $top - self::WEAK_GAP) {
                        $pairs = array_values(array_filter($pairs, static fn (array $p) => $p !== $pair));
                        $pruned = true;
                    }
                }
            }
            if (!$pruned) {
                break;
            }
        }

        $ambiguous = [];
        foreach (self::components($pairs) as $component) {
            $left = array_values(array_unique(array_map(static fn (array $p) => $p[0], $component)));
            $right = array_values(array_unique(array_map(static fn (array $p) => $p[1], $component)));
            sort($left);
            sort($right);
            $ambiguous[] = ['left' => $left, 'right' => $right];
        }

        usort($matches, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return ['matches' => $matches, 'ambiguous' => $ambiguous];
    }

    /**
     * Connected groups of pairs.
     *
     * @param list<array{int, int, float}> $pairs
     *
     * @return list<list<array{int, int, float}>>
     */
    private static function components(array $pairs): array
    {
        $parent = [];
        $find = static function (string $x) use (&$parent, &$find): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        foreach ($pairs as [$l, $r]) {
            $a = "l$l";
            $b = "r$r";
            $parent[$a] ??= $a;
            $parent[$b] ??= $b;
            $parent[$find($a)] = $find($b);
        }
        $groups = [];
        foreach ($pairs as $pair) {
            $groups[$find('l' . $pair[0])][] = $pair;
        }

        return array_values($groups);
    }

    /**
     * @param list<array{int, int, float}> $component
     *
     * @return array{int, int}|null
     */
    private static function acceptOne(array $component): ?array
    {
        if (count($component) === 1) {
            return [$component[0][0], $component[0][1]];
        }
        usort($component, static fn (array $a, array $b) => $b[2] <=> $a[2]);
        foreach ($component as [$l, $r, $score]) {
            if ($score < self::STRONG) {
                continue;
            }
            $rivals = array_filter(
                $component,
                static fn (array $p) => ($p[0] === $l) !== ($p[1] === $r)
            );
            $best = 0.0;
            foreach ($rivals as $p) {
                $best = max($best, $p[2]);
            }
            if ($rivals === [] || $score - $best >= self::MARGIN - 1e-9) {
                return [$l, $r];
            }
        }

        return null;
    }
}
