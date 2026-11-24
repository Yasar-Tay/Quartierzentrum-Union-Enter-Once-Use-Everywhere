<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * WP-CLI: `wp enteronce …`. The entry point for a server scheduler (R-30):
 *
 *     *\/15 * * * * wp --path=/path/to/wordpress enteronce sync --quiet
 */
final class Cli
{
    /** Development-only options (`--now`) need the constant ENTERONCE_DEV (set on the local test site). */
    public static function devMode(): bool
    {
        return defined('ENTERONCE_DEV') && constant('ENTERONCE_DEV') === true;
    }

    /**
     * Runs the sync now.
     *
     * ## OPTIONS
     *
     * [--source=<id>]
     * : Only this centre. Default: every enabled centre.
     *
     * [--release-held=<fingerprint>]
     * : Apply a held run (R-13), only if its list still has this fingerprint (shown by `wp enteronce held`).
     * Needs --source.
     *
     * [--now=<datetime>]
     * : Pretend it is this time (Europe/Zurich). Only on a development site (constant ENTERONCE_DEV).
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function sync(array $args, array $assoc): void
    {
        $plugin = Plugin::instance();
        $clock = $plugin->clock();
        if (isset($assoc['now'])) {
            if (!self::devMode()) {
                \WP_CLI::error('--now ist nur auf einer Entwicklungs-Website verfügbar (Konstante ENTERONCE_DEV).');
            }
            $now = new \DateTimeImmutable((string) $assoc['now'], \EnterOnce\Core\Settings::timezone());
            $clock = new class ($now) implements Clock {
                public function __construct(private readonly \DateTimeImmutable $at)
                {
                }

                public function now(): \DateTimeImmutable
                {
                    return $this->at;
                }
            };
        }
        $runner = $plugin->runner($clock);
        $reports = [];
        if (isset($assoc['release-held'])) {
            $result = self::releaseHeld($runner, (string) ($assoc['source'] ?? ''), $assoc['release-held']);
            if (!$result['ok']) {
                \WP_CLI::error($result['message']);
            }
            \WP_CLI::success($result['message']);
            $reports[] = $result['report'];
        } elseif (isset($assoc['source'])) {
            $reports[] = $runner->run(sanitize_key((string) $assoc['source']), 'cli');
        } else {
            foreach (Options::get()['sources'] as $source) {
                if (!empty($source['enabled'])) {
                    $reports[] = $runner->run((string) $source['id'], 'cli');
                }
            }
        }
        $rows = array_map(static function (RunReport $r): array {
            $a = $r->toArray();
            $c = $r->counts;

            return [
                'source' => $a['source'],
                'status' => $a['status'],
                'dry_run' => $a['dry_run'] ? 'yes' : 'no',
                'held' => $a['held'] ? 'yes' : 'no',
                'create' => $c['create'] ?? 0,
                'update' => $c['update'] ?? 0,
                'unpublish' => $c['unpublish'] ?? 0,
                'keep' => $c['keep'] ?? 0,
                'hold' => $c['hold'] ?? 0,
                'error' => (string) $a['error'],
            ];
        }, $reports);
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['source', 'status', 'dry_run', 'held', 'create', 'update', 'unpublish', 'keep', 'hold', 'error']);
        foreach ($reports as $r) {
            if ($r->status === RunReport::ERROR) {
                \WP_CLI::halt(1);
            }
        }
    }

    /**
     * «Trotzdem anwenden» on the command line: applies a held run only if the held list still has the
     * fingerprint the operator saw with `wp enteronce held` (same rule as the button).
     *
     * @return array{ok: bool, message: string, report: ?RunReport}
     */
    public static function releaseHeld(Runner $runner, string $sourceId, mixed $fingerprint): array
    {
        $sourceId = sanitize_key($sourceId);
        if ($sourceId === '') {
            return ['ok' => false, 'message' => __('Bitte das Zentrum angeben: --source=<Kennung>.', 'enter-once-sync'), 'report' => null];
        }
        $fingerprint = is_string($fingerprint) ? strtolower(trim($fingerprint)) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $fingerprint)) {
            return ['ok' => false, 'message' => __('Bitte den Fingerabdruck der angehaltenen Liste angeben: --release-held=<Fingerabdruck>. «wp enteronce held» zeigt die Liste und ihren Fingerabdruck. Es wurde nichts entfernt.', 'enter-once-sync'), 'report' => null];
        }
        $report = $runner->run($sourceId, 'cli', false, $fingerprint);
        if ($report->status !== RunReport::OK) {
            return ['ok' => false, 'message' => (string) $report->error, 'report' => $report];
        }
        if ($report->error !== null) {
            // The list changed since it was shown: nothing was removed.
            return ['ok' => false, 'message' => $report->error . ' ' . __('Neuer Fingerabdruck: «wp enteronce held».', 'enter-once-sync'), 'report' => $report];
        }

        return ['ok' => true, 'message' => $report->held
            ? __('Kein Eintrag entfernt: der Abgleich ist weiterhin angehalten.', 'enter-once-sync')
            : sprintf(__('Angehaltene Liste angewendet: %d Einträge auf Entwurf gesetzt.', 'enter-once-sync'), (int) ($report->counts['unpublish'] ?? 0)), 'report' => $report];
    }

    /**
     * Shows the held list of each centre (R-13) with its fingerprint, for `sync --release-held=<fingerprint>`.
     *
     * [--source=<id>]
     * : Only this centre.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function held(array $args, array $assoc): void
    {
        foreach (self::heldLines(isset($assoc['source']) ? sanitize_key((string) $assoc['source']) : null) as $line) {
            \WP_CLI::log($line);
        }
    }

    /** @return list<string> German lines for `wp enteronce held` */
    public static function heldLines(?string $only = null): array
    {
        $lines = [];
        $sources = Options::get()['sources'];
        if ($sources === []) {
            return [__('Noch kein Zentrum eingerichtet.', 'enter-once-sync')];
        }
        foreach ($sources as $source) {
            if ($only !== null && $source['id'] !== $only) {
                continue;
            }
            $state = State::get((string) $source['id']);
            if (empty($state['held'])) {
                $lines[] = sprintf(__('%s: kein angehaltener Abgleich.', 'enter-once-sync'), $source['id']);
                continue;
            }
            $held = (array) ($state['held_actions'] ?? []);
            $lines[] = sprintf(__('%1$s: Abgleich angehalten, %2$d Einträge würden entfernt:', 'enter-once-sync'), $source['id'], count($held));
            foreach ($held as $a) {
                $lines[] = '  - ' . $a['title'] . ($a['when'] !== '' ? ' (' . $a['when'] . ')' : '');
            }
            $lines[] = sprintf(__('Fingerabdruck: %s', 'enter-once-sync'), (string) $state['held_hash']);
            $lines[] = sprintf(__('Anwenden: wp enteronce sync --source=%1$s --release-held=%2$s', 'enter-once-sync'), $source['id'], (string) $state['held_hash']);
        }

        return $lines;
    }

    /**
     * Shows the state of every centre.
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $settings = Options::get();
        $now = Plugin::instance()->clock()->now();
        $rows = [];
        foreach ($settings['sources'] as $source) {
            $s = State::get((string) $source['id']);
            $rows[] = [
                'source' => $source['id'],
                'calendar' => Secret::mask((string) $source['ics_url']),
                'enabled' => $source['enabled'] ? 'yes' : 'no',
                'dry_run' => ($s['dry_run'] ?? true) ? 'yes' : 'no',
                'last_success' => (string) ($s['last_success'] ?? ''),
                'stale' => Scheduler::isStale($s, (int) $settings['stale_after_minutes'], $now) ? 'yes' : 'no',
                'held' => !empty($s['held']) ? 'yes' : 'no',
                'error' => (string) ($s['error'] ?? ''),
            ];
        }
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['source', 'calendar', 'enabled', 'dry_run', 'last_success', 'stale', 'held', 'error']);
    }

    /**
     * Sends due digests now.
     *
     * [--force]
     * : Send even if the frequency says not yet.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function digest(array $args, array $assoc): void
    {
        $notifier = new Notifier(Plugin::instance()->clock());
        foreach (Options::sourceIds(Options::get()) as $id) {
            $sent = $notifier->maybeSendDigest($id, isset($assoc['force']));
            \WP_CLI::log($id . ': ' . ($sent ? 'sent' : 'nothing to send'));
        }
    }
}
