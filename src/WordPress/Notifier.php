<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * E-mails (A-19, A-24).
 *  - R-33: the digest of changes and notices at most once per `digest_frequency`.
 *  - Errors and held runs at once; R-32: the same error (and the same held list) at most once a day.
 * Recipients: `sources[].notify_emails`, else the site's administration address.
 * No ICS address, no person data: texts contain titles, dates and German messages only.
 */
final class Notifier
{
    public const DAY = 86400;
    private const MAIL_LOG = 'enteronce_mail_log';
    private const MAX_ENTRIES = 300;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @param list<array<string, mixed>> $changes compact actions (create, update, unpublish)
     * @param list<array<string, mixed>> $notices
     */
    public function queue(string $sourceId, array $changes, array $notices): void
    {
        $d = $this->digest($sourceId);
        $d['changes'] = array_slice(array_merge($d['changes'], $changes), -self::MAX_ENTRIES);
        foreach ($notices as $n) {
            $d['notices'][$n['level'] . '|' . $n['code'] . '|' . $n['uid'] . '|' . ($n['value'] ?? '')] = $n;
        }
        update_option('enteronce_digest_' . $sourceId, $d, false);
    }

    public function maybeSendDigest(string $sourceId, bool $force = false): bool
    {
        $settings = Options::get();
        $d = $this->digest($sourceId);
        if ($d['changes'] === [] && $d['notices'] === []) {
            return false;
        }
        $interval = match ($settings['digest_frequency']) {
            'each_run' => 0,
            'weekly' => 7 * self::DAY,
            default => self::DAY,
        };
        $reference = strtotime((string) ($d['last_sent'] ?? $d['since']));
        $now = $this->clock->now();
        if (!$force && $now->getTimestamp() - (int) $reference < $interval) {
            return false;
        }
        $source = Options::source($settings, $sourceId);
        if ($source === null) {
            return false;
        }
        $label = self::label($source);
        $sent = $this->send($source, sprintf(__('Enter Once: Zusammenfassung für %s', 'enter-once-sync'), $label), $this->digestBody($label, $d));
        update_option('enteronce_digest_' . $sourceId, [
            'since' => $now->format(DATE_ATOM),
            'last_sent' => $now->format(DATE_ATOM),
            'changes' => [],
            'notices' => [],
        ], false);

        return $sent;
    }

    /**
     * R-32: rate-limited by error category (fetch, integrity:<reason>, internal), not by wording,
     * so a message with a changing number is still mailed at most once a day.
     */
    public function error(string $sourceId, string $message, string $category = 'other'): bool
    {
        $source = Options::source(Options::get(), $sourceId);
        if ($source === null || !$this->firstToday('error|' . $sourceId . '|' . $category)) {
            return false;
        }
        $label = self::label($source);
        $body = sprintf(__("Der Abgleich für %s ist fehlgeschlagen. Auf der Website wurde nichts verändert.\n\nGrund: %s\n\nStatus: %s\n", 'enter-once-sync'), $label, $message, self::statusUrl());

        return $this->send($source, sprintf(__('Enter Once: Fehler beim Abgleich (%s)', 'enter-once-sync'), $label), $body);
    }

    /** @param list<array<string, mixed>> $held */
    public function held(string $sourceId, array $held): bool
    {
        $source = Options::source(Options::get(), $sourceId);
        $keys = array_map(static fn (array $a) => (string) $a['key'], $held);
        sort($keys);
        if ($source === null || $held === [] || !$this->firstToday('held|' . $sourceId . '|' . implode(',', $keys))) {
            return false;
        }
        $label = self::label($source);
        $lines = array_map(static fn (array $a) => '- ' . $a['title'] . ($a['when'] !== '' ? ' (' . $a['when'] . ')' : ''), $held);
        $body = sprintf(
            __("Der Abgleich für %1\$s würde %2\$d Einträge auf einmal entfernen. Das ist mehr als die Obergrenze; deshalb wurde keiner entfernt.\n\n%3\$s\n\nPrüfen Sie den Kalender. Wenn alles stimmt, klicken Sie auf der Statusseite auf «Trotzdem anwenden»:\n%4\$s\n", 'enter-once-sync'),
            $label,
            count($held),
            implode("\n", $lines),
            self::statusUrl()
        );

        return $this->send($source, sprintf(__('Enter Once: Abgleich angehalten (%s)', 'enter-once-sync'), $label), $body);
    }

    /** @param array<string, mixed> $source */
    public static function recipients(array $source): array
    {
        $emails = array_values(array_filter(array_map('sanitize_email', (array) $source['notify_emails']), 'is_email'));

        return $emails !== [] ? $emails : [(string) get_option('admin_email')];
    }

    public static function statusUrl(): string
    {
        return admin_url('options-general.php?page=' . Admin::SLUG . '&tab=status');
    }

    /** @param array<string, mixed> $source */
    public static function label(array $source): string
    {
        return (string) ($source['label'] !== '' ? $source['label'] : $source['id']);
    }

    /** @param array<string, mixed> $d */
    private function digestBody(string $label, array $d): string
    {
        $section = static function (string $heading, array $items): string {
            if ($items === []) {
                return '';
            }

            return $heading . ' (' . count($items) . "):\n" . implode("\n", $items) . "\n\n";
        };
        $line = static fn (array $a): string => '- ' . $a['title'] . ($a['when'] !== '' ? ' (' . $a['when'] . ')' : '');
        $created = $updated = $removed = $moved = [];
        foreach ($d['changes'] as $a) {
            match ($a['type']) {
                'create' => $created[] = $line($a),
                'unpublish' => $removed[] = $line($a),
                default => $updated[] = $line($a),
            };
            if (!empty($a['note'])) {
                $moved[$a['key'] . $a['note']] = '- ' . $a['title'] . ': ' . $a['note'];
            }
        }
        $warnings = $infos = [];
        foreach ($d['notices'] as $n) {
            $entry = '- ' . $n['message'];
            if ($n['level'] === 'warning') {
                $warnings[$n['title'] . $entry] = $entry;
            } else {
                $infos[$n['title'] . $entry] = $entry;
            }
        }
        ksort($warnings);
        ksort($infos);

        return sprintf(__("Enter Once – Zusammenfassung für %s\n\n", 'enter-once-sync'), $label)
            . $section(__('Neu auf der Website', 'enter-once-sync'), $created)
            . $section(__('Geändert', 'enter-once-sync'), $updated)
            . $section(__('Auf Entwurf gesetzt', 'enter-once-sync'), $removed)
            . $section(__('Verschobene Termine', 'enter-once-sync'), array_values($moved))
            . $section(__('Warnungen', 'enter-once-sync'), array_values($warnings))
            . $section(__('Hinweise', 'enter-once-sync'), array_values($infos))
            . sprintf(__("Status: %s\n", 'enter-once-sync'), self::statusUrl());
    }

    /** @param array<string, mixed> $source */
    private function send(array $source, string $subject, string $body): bool
    {
        return (bool) wp_mail(self::recipients($source), $subject, $body);
    }

    /** @return array{since: string, last_sent: ?string, changes: list<array<string, mixed>>, notices: array<string, array<string, mixed>>} */
    private function digest(string $sourceId): array
    {
        $d = get_option('enteronce_digest_' . $sourceId, []);
        $d = is_array($d) ? $d : [];

        return [
            'since' => (string) ($d['since'] ?? $this->clock->now()->format(DATE_ATOM)),
            'last_sent' => $d['last_sent'] ?? null,
            'changes' => (array) ($d['changes'] ?? []),
            'notices' => (array) ($d['notices'] ?? []),
        ];
    }

    /** R-32: true the first time a message is seen within a day. */
    private function firstToday(string $message): bool
    {
        $log = get_option(self::MAIL_LOG, []);
        $log = is_array($log) ? $log : [];
        $now = $this->clock->now()->getTimestamp();
        $hash = md5($message);
        foreach ($log as $h => $t) {
            if ($now - (int) $t >= self::DAY) {
                unset($log[$h]);
            }
        }
        if (isset($log[$hash])) {
            update_option(self::MAIL_LOG, $log, false);

            return false;
        }
        $log[$hash] = $now;
        update_option(self::MAIL_LOG, $log, false);

        return true;
    }
}
