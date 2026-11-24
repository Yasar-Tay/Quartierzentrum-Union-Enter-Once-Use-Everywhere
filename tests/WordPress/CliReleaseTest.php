<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\Cli;
use EnterOnce\WordPress\Notifier;
use EnterOnce\WordPress\Runner;
use EnterOnce\WordPress\State;

/** `wp enteronce held` and `wp enteronce sync --release-held=<fingerprint>`: as safe as the button. */
final class CliReleaseTest extends WpTestCase
{
    private const SIX = ['union-mittwochsbar-1125@example', 'union-brunch-1129@example', 'union-theater-1127@example', 'union-repaircafe-1205@example', 'union-seniorensport@example', 'union-kinderpiazza@example'];

    private static function without(string $ics, array $uids): string
    {
        foreach ($uids as $uid) {
            $ics = (string) preg_replace('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '.*?END:VEVENT\r\n/s', '', $ics);
        }

        return $ics;
    }

    private function runner(string $now, string $ics): Runner
    {
        $this->clock->set($now);
        $this->fetcher->set(self::UNION_URL, $ics);

        return new Runner($this->clock, $this->fetcher, new Notifier($this->clock));
    }

    public function testReleaseOnlyWithTheFingerprintOfTheShownList(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $gutted = self::without($ics, self::SIX);
        $this->sync($gutted, '2026-11-19 00:15');
        $this->sync($gutted, '2026-11-19 00:30');

        $lines = Cli::heldLines('union');
        $fingerprint = (string) State::get('union')['held_hash'];
        self::assertStringContainsString('6 Einträge würden entfernt', $lines[0]);
        self::assertContains('Fingerabdruck: ' . $fingerprint, $lines);
        self::assertContains('Anwenden: wp enteronce sync --source=union --release-held=' . $fingerprint, $lines);
        self::assertContains('  - Brunch Atelier Cuisine Globale', $lines);

        // Without a fingerprint (plain flag), with a wrong one, or without --source: refused, nothing removed.
        foreach ([[true, 'union'], ['falsch', 'union'], [str_repeat('0', 32), 'union'], [$fingerprint, '']] as [$given, $source]) {
            $r = Cli::releaseHeld($this->runner('2026-11-19 00:35', $gutted), $source, $given);
            self::assertFalse($r['ok']);
            self::assertNotSame('', $r['message']);
            self::assertCount(8, self::managed('union', 'publish'), 'nothing removed');
        }
        $r = Cli::releaseHeld($this->runner('2026-11-19 00:36', $gutted), 'union', true);
        self::assertStringContainsString('wp enteronce held', $r['message']);

        // The list changes after it was shown: the old fingerprint no longer applies.
        $worse = self::without($gutted, ['union-yoga@example']);
        $this->sync($worse, '2026-11-19 00:40');
        $r = Cli::releaseHeld($this->runner('2026-11-19 00:45', $worse), 'union', $fingerprint);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('hat sich seit der Anzeige geändert', $r['message']);
        self::assertCount(8, self::managed('union', 'publish'));

        // With the current fingerprint: applied.
        $current = (string) State::get('union')['held_hash'];
        self::assertNotSame($fingerprint, $current);
        $r = Cli::releaseHeld($this->runner('2026-11-19 00:50', $worse), 'union', $current);
        self::assertTrue($r['ok'], $r['message']);
        self::assertStringContainsString('7 Einträge auf Entwurf gesetzt', $r['message']);
        self::assertCount(7, self::managed('union', 'draft'));
        self::assertSame(['union: kein angehaltener Abgleich.'], Cli::heldLines('union'));
    }
}
