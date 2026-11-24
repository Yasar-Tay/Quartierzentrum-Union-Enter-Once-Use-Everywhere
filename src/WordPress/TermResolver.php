<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * Plan 4.5: terms are resolved by name at setup and their IDs stored (`enteronce_term_ids`).
 * Resolved again on activation, on every settings save and with «Begriffe neu zuordnen».
 */
final class TermResolver
{
    public const OPTION = 'enteronce_term_ids';
    public const TAX_TOPIC = 'topic';
    public const TAX_TARGETGROUP = 'targetgroup';
    public const TAX_LOCATION = 'location_cat';

    /** @var array<string, array<string, int>> */
    private array $ids;

    public function __construct()
    {
        $stored = get_option(self::OPTION, []);
        $this->ids = is_array($stored) ? $stored : [];
    }

    /**
     * Resolve every term name used in the maps and store the IDs.
     *
     * @param array<string, mixed> $settings
     * @return list<string> names that have no term («Thema: X»)
     */
    public function resolveAll(array $settings): array
    {
        $missing = [];
        $this->ids = [self::TAX_TOPIC => [], self::TAX_TARGETGROUP => []];
        foreach ([self::TAX_TOPIC => 'category_map', self::TAX_TARGETGROUP => 'tag_map'] as $tax => $key) {
            foreach ((array) $settings[$key] as $targets) {
                foreach ((array) $targets as $name) {
                    $name = (string) $name;
                    if ($name === '' || isset($this->ids[$tax][$name])) {
                        continue;
                    }
                    $id = self::lookup($name, $tax);
                    if ($id === null) {
                        $missing[] = ($tax === self::TAX_TOPIC ? __('Thema', 'enter-once-sync') : __('Zielgruppe', 'enter-once-sync')) . ': ' . $name;
                    } else {
                        $this->ids[$tax][$name] = $id;
                    }
                }
            }
        }
        update_option(self::OPTION, $this->ids, false);

        return array_values(array_unique($missing));
    }

    /**
     * Stored IDs for names; names without a stored ID are looked up once more and reported.
     *
     * @param list<string> $names
     * @param list<string> $missing receives names without a term
     * @return list<int>
     */
    public function ids(string $taxonomy, array $names, array &$missing = []): array
    {
        $out = [];
        foreach ($names as $name) {
            $id = $this->ids[$taxonomy][$name] ?? self::lookup($name, $taxonomy);
            if ($id === null) {
                $missing[] = $name;
                continue;
            }
            $this->ids[$taxonomy][$name] = $id;
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }

    public static function lookup(string $name, string $taxonomy): ?int
    {
        if (!taxonomy_exists($taxonomy)) {
            return null;
        }
        $term = get_term_by('name', $name, $taxonomy);
        if ($term instanceof \WP_Term) {
            return (int) $term->term_id;
        }
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_array($terms)) {
            foreach ($terms as $t) {
                if ($t instanceof \WP_Term && html_entity_decode($t->name, ENT_QUOTES, 'UTF-8') === $name) {
                    return (int) $t->term_id;
                }
            }
        }

        return null;
    }
}
