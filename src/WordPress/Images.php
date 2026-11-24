<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * A-15: images live in the media library, named after the event title. Priority: the event's
 * own image, then the category image (`category_images`), then `default_image_id`.
 *
 * Lookup is limited to images uploaded for the centre: an attachment counts for a centre when
 * its field «Zentrum (Enter Once)» names that centre (meta `_enteronce_image_source`).
 * Title matching ignores case, accents, spaces, underscores, hyphens and the file extension.
 */
final class Images
{
    public const META_SOURCE = '_enteronce_image_source';

    /** @var array<string, array<string, int>> source => normalised name => attachment id */
    private array $index = [];

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function enabled(): bool
    {
        return ($this->settings['image_mode'] ?? 'media_library') === 'media_library';
    }

    /**
     * @param list<string> $categories calendar category names
     */
    public function resolve(string $sourceId, string $title, array $categories): ?int
    {
        if (!$this->enabled()) {
            return null;
        }
        $own = $this->forTitle($sourceId, $title);
        if ($own !== null) {
            return $own;
        }
        $byCategory = (array) ($this->settings['category_images'] ?? []);
        foreach ($categories as $category) {
            $id = (int) ($byCategory[$category] ?? 0);
            if ($id > 0 && wp_attachment_is_image($id)) {
                return $id;
            }
        }
        $default = (int) ($this->settings['default_image_id'] ?? 0);

        return $default > 0 && wp_attachment_is_image($default) ? $default : null;
    }

    public function forTitle(string $sourceId, string $title): ?int
    {
        $index = $this->index($sourceId);

        return $index[self::normalise($title)] ?? null;
    }

    /** @return array{url: string, alt: string}|null */
    public static function feedImage(?int $attachmentId, string $title): ?array
    {
        if ($attachmentId === null) {
            return null;
        }
        $url = wp_get_attachment_image_url($attachmentId, 'large');
        if (!is_string($url) || $url === '') {
            return null;
        }
        $alt = trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));

        return ['url' => $url, 'alt' => $alt !== '' ? $alt : $title];
    }

    public static function normalise(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        $name = (string) preg_replace('/\.(jpe?g|png|gif|webp|avif)$/i', '', trim($name));
        $name = remove_accents($name);

        return (string) preg_replace('/[\s_\-]+/u', '', mb_strtolower($name));
    }

    /** @return array<string, int> */
    private function index(string $sourceId): array
    {
        if (isset($this->index[$sourceId])) {
            return $this->index[$sourceId];
        }
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [['key' => self::META_SOURCE, 'value' => $sourceId]],
        ]);
        $index = [];
        foreach ($ids as $id) {
            $file = (string) get_post_meta((int) $id, '_wp_attached_file', true);
            foreach ([wp_basename($file), (string) get_the_title((int) $id)] as $name) {
                $n = self::normalise($name);
                if ($n !== '' && !isset($index[$n])) {
                    $index[$n] = (int) $id;
                }
            }
        }

        return $this->index[$sourceId] = $index;
    }

    /**
     * Field «Zentrum (Enter Once)» on the media edit screen. Only administrators may change it
     * (review): it decides which centre's events show the image. Others see the value read-only.
     */
    public static function register(): void
    {
        add_filter('attachment_fields_to_edit', [self::class, 'editField'], 10, 2);
        add_filter('attachment_fields_to_save', [self::class, 'saveField'], 10, 2);
    }

    /** @param array<string, mixed> $fields */
    public static function editField(array $fields, \WP_Post $post): array
    {
        if (!wp_attachment_is_image($post->ID) || !current_user_can('upload_files')) {
            return $fields;
        }
        $current = (string) get_post_meta($post->ID, self::META_SOURCE, true);
        $labels = ['' => __('– keinem Zentrum –', 'enter-once-sync')];
        foreach (Options::get()['sources'] as $source) {
            $labels[(string) $source['id']] = $source['label'] !== '' ? (string) $source['label'] : (string) $source['id'];
        }
        if (current_user_can(Admin::CAP)) {
            $options = '';
            foreach ($labels as $id => $label) {
                $options .= '<option value="' . esc_attr($id) . '"' . selected($current, $id, false) . '>' . esc_html($label) . '</option>';
            }
            $html = '<select name="attachments[' . (int) $post->ID . '][enteronce_image_source]">' . $options . '</select>';
            $help = __('Bild für die Veranstaltungen dieses Zentrums. Der Dateiname entspricht dem Titel, z. B. Offener_Deutschtreff.jpg.', 'enter-once-sync');
        } else {
            $html = esc_html($labels[$current] ?? $current);
            $help = __('Nur Administratorinnen und Administratoren können das Zentrum eines Bildes ändern.', 'enter-once-sync');
        }
        $fields['enteronce_image_source'] = [
            'label' => __('Zentrum (Enter Once)', 'enter-once-sync'),
            'input' => 'html',
            'html' => $html,
            'helps' => $help,
        ];

        return $fields;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    public static function saveField(array $post, array $attachment): array
    {
        if (isset($attachment['enteronce_image_source']) && current_user_can(Admin::CAP)) {
            $value = sanitize_key((string) $attachment['enteronce_image_source']);
            if ($value === '') {
                delete_post_meta((int) $post['ID'], self::META_SOURCE);
            } else {
                update_post_meta((int) $post['ID'], self::META_SOURCE, $value);
            }
        }

        return $post;
    }
}
