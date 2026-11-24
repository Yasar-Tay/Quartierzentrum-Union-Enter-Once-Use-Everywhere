<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use EnterOnce\Drift\Resolver;
use EnterOnce\Drift\TitleSimilarity;
use PHPUnit\Framework\TestCase;

final class TitleSimilarityTest extends TestCase
{
    /** @dataProvider alike */
    public function testTitlesThatDifferBetweenCalendarAndSiteAreAlike(string $a, string $b): void
    {
        self::assertGreaterThanOrEqual(0.7, TitleSimilarity::score($a, $b), "$a / $b");
    }

    /** @return iterable<string, array{string, string}> */
    public static function alike(): iterable
    {
        yield 'accents' => ['Café Balance', 'Café Bâlance'];
        yield 'test prefix' => ['Test: Café Balance', 'Café Bâlance'];
        yield 'suffix letter' => ['Test: Deutsch im Alltag', 'Deutsch im Alltag A'];
        yield 'weekday and time in the site title' => ['Yoga im Union', 'Yoga am Montag (Start 09:00 Uhr)'];
        yield 'entity and punctuation' => ['Schach- und Denkspiele', 'Jugendschach & Schach- und Denkspiele'];
        yield 'identical' => ['Kinderpiazza', 'Kinderpiazza'];
        yield 'case' => ['KINDERPIAZZA', 'kinderpiazza'];
        yield 'umlaut folding' => ['Treffpunkt für ältere Quartierbewohner:innen', 'Treffpunkt fuer aeltere Quartierbewohner:innen'];
    }

    /** @dataProvider unlike */
    public function testUnrelatedTitlesAreNotAlike(string $a, string $b): void
    {
        self::assertLessThan(0.6, TitleSimilarity::score($a, $b), "$a / $b");
    }

    /** @return iterable<string, array{string, string}> */
    public static function unlike(): iterable
    {
        yield ['Repair Café', 'Gottesdienst'];
        yield ['Yoga im Union', 'Kinderpiazza'];
        yield ['Wir reden Deutsch', 'Offener Treffpunkt'];
    }

    public function testTitlesMadeOnlyOfFillerWordsStillCompare(): void
    {
        self::assertSame(1.0, TitleSimilarity::score('Im Union', 'im union'));
        self::assertLessThan(0.5, TitleSimilarity::score('Im Union', 'Am Montag'));
    }

    public function testResolverMatchesUniquePairsWhateverTheScore(): void
    {
        $r = Resolver::resolve([0 => [0 => 0.1]]);

        self::assertSame([[0, 0]], $r['matches']);
        self::assertSame([], $r['ambiguous']);
    }

    public function testResolverNeedsAClearMarginAmongSeveralCandidates(): void
    {
        $tie = Resolver::resolve([0 => [0 => 0.9, 1 => 0.9]]);
        self::assertSame([], $tie['matches']);
        self::assertSame([['left' => [0], 'right' => [0, 1]]], $tie['ambiguous']);

        $close = Resolver::resolve([0 => [0 => 0.9, 1 => 0.8]]);
        self::assertSame([], $close['matches'], 'a lead of 0.1 is below the margin');

        $clear = Resolver::resolve([0 => [0 => 0.95, 1 => 0.5]]);
        self::assertSame([[0, 0]], $clear['matches']);
    }

    public function testResolverDoesNotAcceptAWeakWinner(): void
    {
        $r = Resolver::resolve([0 => [0 => 0.5, 1 => 0.45]]);

        self::assertSame([], $r['matches']);
        self::assertCount(1, $r['ambiguous']);
    }

    public function testResolverKeepsAmbiguousGroupsApart(): void
    {
        $r = Resolver::resolve([
            0 => [0 => 1.0, 1 => 1.0],        // two identical right-hand candidates
            1 => [2 => 1.0, 0 => 0.1],        // a clear pair that also touches the first group weakly
        ]);

        self::assertSame([[1, 2]], $r['matches']);
        self::assertSame([['left' => [0], 'right' => [0, 1]]], $r['ambiguous']);
    }
}
