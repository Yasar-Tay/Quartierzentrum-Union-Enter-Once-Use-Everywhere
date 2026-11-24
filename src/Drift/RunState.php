<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * The small state file that enforces plan rule R-40 between runs:
 *  - at most one live run per hour;
 *  - after a 403 or 429 answer: no live run for the rest of that day (Europe/Zurich).
 *
 * The file sits next to the output file (`enteronce-drift.state.json`, shared by all reports in
 * that folder, whatever their names). It holds timestamps and a status code, nothing else.
 * Runs that read a saved page file make no request and neither read nor write it.
 */
final class RunState
{
    public const FILE_NAME = 'enteronce-drift.state.json';
    public const MIN_INTERVAL_SECONDS = 3600;

    public const REFUSED_TOO_SOON = 'too_soon';
    public const REFUSED_STOPPED_TODAY = 'stopped_today';
    public const REFUSED_UNREADABLE = 'state_unreadable';

    public function __construct(private readonly string $file)
    {
    }

    public static function besideOutput(string $outputFile): self
    {
        $dir = dirname($outputFile);

        return new self(($dir === '' ? '.' : $dir) . DIRECTORY_SEPARATOR . self::FILE_NAME);
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * Why a live run may not start now, or null if it may.
     *
     * @return array{reason: string, until: ?DateTimeImmutable}|null
     */
    public function refusal(DateTimeImmutable $now): ?array
    {
        $state = $this->load();
        if ($state === null) {
            return ['reason' => self::REFUSED_UNREADABLE, 'until' => null];
        }
        $tz = new DateTimeZone('Europe/Zurich');
        $local = $now->setTimezone($tz);

        $stopDay = $state['stop_day'] ?? null;
        if (is_string($stopDay) && $stopDay === $local->format('Y-m-d')) {
            return ['reason' => self::REFUSED_STOPPED_TODAY, 'until' => $local->setTime(0, 0)->modify('+1 day')];
        }
        $last = isset($state['last_run']) && is_string($state['last_run']) ? date_create_immutable($state['last_run']) : false;
        if ($last !== false) {
            $next = $last->modify('+' . self::MIN_INTERVAL_SECONDS . ' seconds');
            if ($now < $next) {
                return ['reason' => self::REFUSED_TOO_SOON, 'until' => $next->setTimezone($tz)];
            }
        }

        return null;
    }

    /** Called before the request, so a run that crashes still counts. */
    public function recordRun(DateTimeImmutable $now): void
    {
        $state = $this->load() ?? [];
        $state['version'] = 1;
        $state['last_run'] = $now->format(DATE_ATOM);
        $this->save($state);
    }

    /** Called on a 403 or 429 answer: no further live run today. */
    public function recordStop(DateTimeImmutable $now, int $status): void
    {
        $state = $this->load() ?? [];
        $state['version'] = 1;
        $state['stop_day'] = $now->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d');
        $state['stop_status'] = $status;
        $state['stop_at'] = $now->format(DATE_ATOM);
        $this->save($state);
    }

    /**
     * @return array<string, mixed>|null [] when there is no file yet, null when it exists but is unreadable
     */
    private function load(): ?array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $text = @file_get_contents($this->file);
        $data = $text === false ? null : json_decode($text, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        $tmp = $this->file . '.tmp';
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException('Die Statusdatei kann nicht geschrieben werden: ' . $this->file);
        }
    }
}
