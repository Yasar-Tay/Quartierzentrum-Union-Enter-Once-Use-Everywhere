<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use EnterOnce\Core\CancelRule;
use EnterOnce\Core\ContentHash;
use EnterOnce\Core\DescriptionParser;
use EnterOnce\Core\Mapper;
use EnterOnce\Core\MovedDateNote;
use EnterOnce\Core\Settings;
use EnterOnce\Core\TitleNormaliser;
use PHPUnit\Framework\TestCase;

/** Block parser, clean-up, mapping, cancel rule, note markers, hash (plan sections 4.4, 4.5, phase 1). */
final class TextAndMappingTest extends TestCase
{
    public function testBlockParserTolerance(): void
    {
        $text = "Text oben.\n\n[TAG]\nname = Kinder\n\n[/tag]\n\n[event-type]\n\nname= Bildung\nname=Kultur \n\n[/event-type]\n[tag]\nname=Familie";
        $p = DescriptionParser::parse($text);
        self::assertSame(['Bildung', 'Kultur'], $p['categories']);
        self::assertSame(['Kinder', 'Familie'], $p['tags'], 'upper case marker, spaces around =, unclosed last block, any order');
        self::assertSame('Text oben.', $p['text']);
        self::assertSame('<p>Text oben.</p>', $p['html']);
    }

    public function testUnclosedBlockEndsAtTextLine(): void
    {
        $p = DescriptionParser::parse("Oben.\n[tag]\nname=Kinder\nWeitere Infos unten.");
        self::assertSame(['Kinder'], $p['tags']);
        self::assertSame("Oben.\nWeitere Infos unten.", $p['text']);
    }

    public function testRemnantsAndHtml(): void
    {
        $p = DescriptionParser::parse("Mail: a@b.ch<mailto:a@b.ch>, Web <https://example.org/x> und <tel:+41000>.\nZweite <Zeile> & mehr");
        self::assertSame("Mail: a@b.ch, Web  und .\nZweite <Zeile> & mehr", $p['text']);
        self::assertSame('<p>Mail: a@b.ch, Web  und .<br>Zweite &lt;Zeile&gt; &amp; mehr</p>', $p['html']);

        $h = DescriptionParser::parse('<p>Hallo <b>Welt</b><br>Zeile 2</p><script>alert(1)</script><p>[event-type]<br>name=Musik<br>[/event-type]</p>');
        self::assertSame(['Musik'], $h['categories']);
        self::assertSame("<p>Hallo Welt<br>Zeile 2</p>", $h['html']);
        self::assertStringNotContainsString('script', $h['html']);
    }

    public function testMappingResolution(): void
    {
        $m = new Mapper(new Settings([], 'union'));
        $r = $m->resolve(['Kulturr', 'bildung', 'Kulinarisches', 'Unbekannt', 'Musi', 'Kreativitat'], 'category');
        self::assertSame(['Kultur', 'Bildung', 'Essen & Trinken', 'Musik', 'Kreativität'], $r['values']);
        self::assertSame([
            ['kind' => 'corrected', 'value' => 'Kulturr', 'corrected_to' => 'Kultur'],
            ['kind' => 'dropped', 'value' => 'Unbekannt', 'corrected_to' => null],
            ['kind' => 'corrected', 'value' => 'Musi', 'corrected_to' => 'Musik'],
            ['kind' => 'corrected', 'value' => 'Kreativitat', 'corrected_to' => 'Kreativität'],
        ], $r['issues'], 'exact match ignores case; synonym is silent; one character in UTF-8 counts once');

        // «Beratung» and «Bewegung» differ by three characters: never confused.
        self::assertFalse(Mapper::distanceAtMostOne('beratung', 'bewegung'));
        self::assertSame(['Beratung'], $m->resolve(['Bewatung'], 'category')['values']);
        self::assertSame(['Für Kinder', 'Für Erwachsene'], $m->targetGroups(['Familie']));
        self::assertSame([], $m->targetGroups(['Deutschlernende', 'Highlight']));
        self::assertSame(['Kulturelles & Musik'], $m->topics(['Kultur', 'Musik']));
    }

    public function testAmbiguousSpellingIsDropped(): void
    {
        $settings = new Settings(['category_map' => ['Tanz' => ['A'], 'Tank' => ['B']]], 'union');
        $r = (new Mapper($settings))->resolve(['Tan'], 'category');
        self::assertSame([], $r['values'], 'two candidates → not corrected');
        self::assertSame('dropped', $r['issues'][0]['kind']);
    }

    public function testCancelRule(): void
    {
        self::assertSame(['Konzert', true], CancelRule::apply('Abgesagt: Konzert', '', ['Abgesagt:', 'Canceled:', 'Cancelled:']));
        self::assertSame(['Konzert', true], CancelRule::apply('ABGESAGT:Konzert', 'CONFIRMED', ['Abgesagt:']));
        self::assertSame(['Konzert', true], CancelRule::apply('Konzert', 'CANCELLED', ['Abgesagt:']));
        self::assertSame(['Abgesagtes Konzert', false], CancelRule::apply('Abgesagtes Konzert', '', ['Abgesagt:']));
    }

    public function testNoteMarkersCanBeRemovedAgain(): void
    {
        $body = '<p>Eigener Text der Website.</p>';
        $with = MovedDateNote::apply($body, 'Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr');
        self::assertSame('<!-- enteronce:note --><p class="enteronce-note">Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr</p><!-- /enteronce:note --><p>Eigener Text der Website.</p>', $with);
        self::assertSame($body, MovedDateNote::strip($with));
        self::assertSame($body, MovedDateNote::apply($with, null));
        $twice = MovedDateNote::apply($with, 'Neue Notiz');
        self::assertSame(1, substr_count($twice, 'enteronce-note'));
        // Comments stripped by an editor: the paragraph class still marks it.
        self::assertSame($body, MovedDateNote::strip('<p class="enteronce-note">Alt</p>' . $body));
    }

    public function testTitleNormaliser(): void
    {
        self::assertSame('yoga im union', TitleNormaliser::normalise('  Yoga  im Union! '));
        self::assertSame('kinderpiazza', TitleNormaliser::normalise('Abgesagt: Kinderpiazza', ['Abgesagt:']));
        self::assertNotSame(TitleNormaliser::normalise('Yoga im Union'), TitleNormaliser::normalise('Yoga im Union (Frühling)'));
        self::assertSame('café bâlance', TitleNormaliser::normalise('Café Bâlance'));
    }

    public function testContentHash(): void
    {
        $a = ContentHash::of(['title' => 'X', 'topics' => ['B', 'A'], 'time_start' => '09:00']);
        $b = ContentHash::of(['time_start' => '09:00', 'topics' => ['A', 'B'], 'title' => 'X']);
        self::assertSame($a, $b, 'key order and term order do not matter');
        self::assertNotSame($a, ContentHash::of(['title' => 'X', 'topics' => ['A', 'B'], 'time_start' => '09:15']));
        self::assertSame(
            ContentHash::of(['title' => 'X', 'body' => 'a', 'room' => 'R'], true),
            ContentHash::of(['title' => 'Y', 'body' => 'b', 'room' => 'R'], true),
            'keep_text hash ignores title and body'
        );
        self::assertStringStartsWith('v1:', $a);
    }

    public function testSettingsDefaults(): void
    {
        $s = new Settings([], 'union');
        self::assertSame(2, $s->removalGraceRuns);
        self::assertSame(5, $s->bulkUnpublishLimit);
        self::assertSame(4, $s->weeklyMinDates);
        self::assertSame(14, $s->weeklyLeadDays);
        self::assertSame(3, $s->windowMonthsAhead);
        self::assertSame('unpublish', $s->cancelMode);
        self::assertSame('weekly_offer', $s->weeklyMode);
        self::assertTrue($s->movedDateNote);
        self::assertTrue($s->isDryRun());
        self::assertNull($s->tagMap['Highlight']);
    }
}
