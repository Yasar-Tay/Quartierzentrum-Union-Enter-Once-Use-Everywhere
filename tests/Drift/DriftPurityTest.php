<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use PHPUnit\Framework\TestCase;

/**
 * Guards the rules that are easy to break by accident in a later edit:
 * R-40 (only plain page requests, never the list request), R-41 (writes), no WordPress, no network library.
 */
final class DriftPurityTest extends TestCase
{
    /** @return array<string, string> file name → source text */
    private static function sources(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Drift/*.php') ?: [] as $file) {
            $out[basename($file)] = (string) file_get_contents($file);
        }
        self::assertNotEmpty($out);

        return $out;
    }

    public function testNoNetworkFunctionOutsideTheTwoFetchers(): void
    {
        $allowed = ['HttpPageFetcher.php', 'IcsLoader.php'];
        foreach (self::sources() as $name => $code) {
            self::assertDoesNotMatchRegularExpression('/\b(curl_\w+|fsockopen|stream_socket_client|socket_create|gethostbyname)\s*\(/', $code, "$name: only file_get_contents streams are used");
            if (in_array($name, $allowed, true)) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/\b(stream_context_create|gethostbynamel|file_get_contents\s*\(\s*\$url)/', $code, "$name must not reach the network");
        }
    }

    public function testOnlyTheRunnerAndTheStateFileWriteFiles(): void
    {
        foreach (self::sources() as $name => $code) {
            if (in_array($name, ['DriftRunner.php', 'RunState.php'], true)) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/\b(file_put_contents|fopen|fwrite|unlink|rename|mkdir|touch|copy)\s*\(/', $code, "$name must not write (R-41)");
        }
    }

    public function testTheListRequestIsNamedOnlyWhereItIsForbidden(): void
    {
        foreach (self::sources() as $name => $code) {
            if ($name === 'PageAddress.php') {
                continue;
            }
            self::assertStringNotContainsString('admin-ajax', $code, $name);
            self::assertStringNotContainsString('filter_events', $code, $name);
        }
    }

    public function testNoWordPressFunctionAndNoClockInTheMatchingAndReading(): void
    {
        foreach (self::sources() as $name => $code) {
            self::assertDoesNotMatchRegularExpression('/\b(wp_\w+|add_action|add_filter|get_option|esc_html|__\()\s*/', $code, "$name: no WordPress");
        }
        foreach (['Matcher.php', 'PageReader.php', 'Resolver.php', 'TitleSimilarity.php', 'ReportRenderer.php', 'CalendarSide.php'] as $name) {
            $code = self::sources()[$name];
            self::assertDoesNotMatchRegularExpression('/\b(time|date|microtime|strtotime)\s*\(|new DateTimeImmutable\(\s*(\'now\'|"now")/', $code, "$name takes \"now\" as a parameter");
        }
    }

    public function testRendererHasNoScriptAndNoExternalReference(): void
    {
        $code = self::sources()['ReportRenderer.php'];
        self::assertDoesNotMatchRegularExpression('/<script|<link |<img |@import|url\(|src=/i', $code);
        self::assertDoesNotMatchRegularExpression('#https?://#', $code, 'no address is written into the report template');
    }

    public function testMessagesUseSwissSpelling(): void
    {
        // Files that hold text users see. (TitleSimilarity folds «ß» on purpose, when it reads titles.)
        foreach (['ReportRenderer.php', 'DriftRunner.php', 'RunState.php', 'PageStructureError.php', 'RunOutcome.php', 'IcsLoader.php', 'Matcher.php', 'PageAddress.php', 'HttpPageFetcher.php', 'FilePageFetcher.php'] as $name) {
            self::assertStringNotContainsString('ß', self::sources()[$name], $name);
        }
        self::assertStringNotContainsString('ß', (string) file_get_contents(dirname(__DIR__, 2) . '/bin/enteronce-drift'));
    }
}
