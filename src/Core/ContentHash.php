<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * The content hash of section 4.4: computed from the values that will be written
 * to WordPress, after mapping. DTSTAMP, LAST-MODIFIED and SEQUENCE never reach the
 * target record, so they cannot enter the hash. The publish status is not part of
 * the hash either; the diff compares it separately.
 *
 * Canonical form: keys sorted, lists of terms sorted, JSON without escaping of
 * Unicode or slashes, SHA-256, prefixed with "v1:" so the rule can change later.
 */
final class ContentHash
{
    /** Fields that the site keeps when a record has `_enteronce_keep_text` (A-14). */
    public const TEXT_FIELDS = ['title', 'body'];

    /** Lists whose order does not matter. */
    private const SET_FIELDS = ['topics', 'target_groups'];

    /** @param array<string, mixed> $fields */
    public static function of(array $fields, bool $withoutText = false): string
    {
        if ($withoutText) {
            foreach (self::TEXT_FIELDS as $f) {
                unset($fields[$f]);
            }
        }
        foreach (self::SET_FIELDS as $f) {
            if (isset($fields[$f]) && is_array($fields[$f])) {
                $list = array_map('strval', $fields[$f]);
                sort($list, SORT_STRING);
                $fields[$f] = $list;
            }
        }
        ksort($fields, SORT_STRING);
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'v1:' . hash('sha256', $json);
    }
}
