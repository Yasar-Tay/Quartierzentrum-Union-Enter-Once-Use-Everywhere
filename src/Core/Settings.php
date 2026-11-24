<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * Read-only view of the `enteronce_settings` array (plan section 6.6) for one source.
 *
 * The core never reads WordPress options: the shell passes the stored array in.
 * Missing keys fall back to the defaults of section 6.6. Keys the core does not
 * use are kept in {@see Settings::$raw} untouched.
 */
final class Settings
{
    public const TIMEZONE = 'Europe/Zurich';

    /** @var array<string, mixed> */
    public readonly array $raw;
    /** @var array<string, mixed> the entry of `sources[]` whose id is $sourceId */
    public readonly array $source;
    public readonly string $sourceId;

    public readonly int $windowMonthsAhead;
    public readonly int $removalGraceRuns;
    public readonly int $bulkUnpublishLimit;
    public readonly bool $internalClassNotPublic;
    /** @var list<string> */
    public readonly array $internalCategories;
    /** @var list<string> */
    public readonly array $cancelPrefixes;
    public readonly string $cancelMode;
    public readonly string $weeklyMode;
    public readonly int $weeklyMinDates;
    public readonly int $weeklyLeadDays;
    public readonly bool $movedDateNote;
    /** @var array<string, list<string>|null> */
    public readonly array $categoryMap;
    /** @var array<string, list<string>|null> */
    public readonly array $tagMap;
    /** @var array<string, string> */
    public readonly array $synonyms;
    public readonly bool $fieldMapVerified;
    public readonly bool $adoptKeepText;

    /**
     * @param array<string, mixed> $settings the stored `enteronce_settings` array (may be empty)
     */
    public function __construct(array $settings, string $sourceId)
    {
        $this->raw = $settings;
        $this->sourceId = $sourceId;

        $source = ['id' => $sourceId];
        foreach ((array) ($settings['sources'] ?? []) as $entry) {
            if (is_array($entry) && (string) ($entry['id'] ?? '') === $sourceId) {
                $source = $entry;
                break;
            }
        }
        $this->source = $source + [
            'id' => $sourceId,
            'label' => $sourceId,
            'location_term' => null,
            'enabled' => true,
            'dry_run' => true,
        ];

        $d = self::defaults();
        $get = static fn (string $key) => array_key_exists($key, $settings) ? $settings[$key] : $d[$key];

        $this->windowMonthsAhead = (int) $get('window_months_ahead');
        $this->removalGraceRuns = max(1, (int) $get('removal_grace_runs'));
        $this->bulkUnpublishLimit = max(0, (int) $get('bulk_unpublish_limit'));

        $markers = (array) $get('internal_markers');
        $this->internalClassNotPublic = (bool) ($markers['class_not_public'] ?? true);
        $this->internalCategories = array_values(array_map('strval', (array) ($markers['categories'] ?? ['Intern'])));

        $this->cancelPrefixes = array_values(array_map('strval', (array) $get('cancel_prefixes')));
        $this->cancelMode = (string) $get('cancel_mode') === 'badge' ? 'badge' : 'unpublish';
        $this->weeklyMode = (string) $get('weekly_mode') === 'per_date' ? 'per_date' : 'weekly_offer';
        $this->weeklyMinDates = max(1, (int) $get('weekly_min_dates'));
        $this->weeklyLeadDays = max(0, (int) $get('weekly_lead_days'));
        $this->movedDateNote = (bool) $get('moved_date_note');
        $this->categoryMap = self::normaliseMap((array) $get('category_map'));
        $this->tagMap = self::normaliseMap((array) $get('tag_map'));
        $this->synonyms = array_map('strval', (array) $get('synonyms'));
        $this->fieldMapVerified = (bool) $get('field_map_verified');
        $this->adoptKeepText = (bool) $get('adopt_keep_text');
    }

    /**
     * Defaults of plan section 6.6 for every key the core reads.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'window_months_ahead' => 3,
            'removal_grace_runs' => 2,
            'bulk_unpublish_limit' => 5,
            'internal_markers' => ['class_not_public' => true, 'categories' => ['Intern']],
            'cancel_prefixes' => ['Abgesagt:', 'Canceled:', 'Cancelled:'],
            'cancel_mode' => 'unpublish',
            'weekly_mode' => 'weekly_offer',
            'weekly_min_dates' => 4,
            'weekly_lead_days' => 14,
            'moved_date_note' => true,
            'category_map' => [
                'Essen & Trinken' => ['Essen & Trinken'],
                'Kultur' => ['Kulturelles & Musik'],
                'Musik' => ['Kulturelles & Musik'],
                'Beratung' => ['Beratung & Bildung'],
                'Bildung' => ['Beratung & Bildung'],
                'Bewegung' => ['Bewegung & Entspannung'],
                'Kreativität' => ['Kreativität & Spielen'],
                'Offener Treffpunkt' => ['Offener Treffpunkt'],
                'Party' => ['Märkte & Feste'],
                'Austausch' => ['Offener Treffpunkt'],
            ],
            'tag_map' => [
                'Babys & Kleinkinder' => ['Für Babys und Kleinkinder'],
                'Kinder' => ['Für Kinder'],
                'Jugendliche' => ['Für Jugendliche'],
                'Erwachsene' => ['Für Erwachsene'],
                'Senior:innen' => ['Für Senioren'],
                'Familie' => ['Für Kinder', 'Für Erwachsene'],
                'Deutschlernende' => null,
                'Highlight' => null,
            ],
            'synonyms' => [
                'Familien' => 'Familie',
                'Kulinarisches' => 'Essen & Trinken',
            ],
            'field_map_verified' => false,
            'adopt_keep_text' => true,
        ];
    }

    /** R-11 and R-31: dry run when the source says so or the field mapping is not verified. */
    public function isDryRun(): bool
    {
        return (bool) $this->source['dry_run'] || !$this->fieldMapVerified;
    }

    /** `sources[].location_term`, or null when not set. */
    public function locationTerm(): int|string|null
    {
        $term = $this->source['location_term'] ?? null;

        return is_int($term) || is_string($term) ? $term : null;
    }

    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /**
     * A map value may be a string, a list of strings, or null ("deliberately not transferred").
     *
     * @param array<mixed> $map
     * @return array<string, list<string>|null>
     */
    private static function normaliseMap(array $map): array
    {
        $out = [];
        foreach ($map as $name => $targets) {
            if ($targets === null) {
                $out[(string) $name] = null;
                continue;
            }
            $list = array_values(array_filter(array_map('strval', (array) $targets), static fn ($v) => $v !== ''));
            $out[(string) $name] = $list === [] ? null : $list;
        }

        return $out;
    }
}
