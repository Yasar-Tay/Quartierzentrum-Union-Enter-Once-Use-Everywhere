<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/** Wires the plugin into WordPress. */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain('enter-once-sync', false, dirname(plugin_basename(ENTERONCE_PLUGIN_FILE)) . '/languages');
        });
        Scheduler::register();
        Images::register();
        add_action('rest_api_init', function (): void {
            (new FeedController($this->clock()))->register();
        });
        add_filter('rest_pre_serve_request', [FeedController::class, 'filterServe'], 20, 3);
        if (is_admin()) {
            (new Admin())->register();
            EditNotice::register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists(\WP_CLI::class)) {
            \WP_CLI::add_command('enteronce', Cli::class);
        }
    }

    /** "Now" for the whole plugin; tests and development may replace it (`enteronce_clock`). */
    public function clock(): Clock
    {
        $clock = apply_filters('enteronce_clock', null);

        return $clock instanceof Clock ? $clock : new SystemClock();
    }

    /** The calendar download; tests and development may replace it (`enteronce_fetcher`). */
    public function fetcher(): Fetcher
    {
        $fetcher = apply_filters('enteronce_fetcher', new HttpFetcher());

        return $fetcher instanceof Fetcher ? $fetcher : new HttpFetcher();
    }

    public function runner(?Clock $clock = null, ?Fetcher $fetcher = null): Runner
    {
        $clock ??= $this->clock();

        return new Runner($clock, $fetcher ?? $this->fetcher(), new Notifier($clock));
    }

    public static function activate(): void
    {
        if (get_option(Options::OPTION) === false) {
            add_option(Options::OPTION, Options::defaults(), '', false);
        }
        Scheduler::register();
        Scheduler::schedule(true);
        (new TermResolver())->resolveAll(Options::get());
    }

    public static function deactivate(): void
    {
        Scheduler::unschedule();
    }
}
