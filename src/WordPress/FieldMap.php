<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * The learned field mapping (`field_map`, A-03). Keys and entry shapes:
 *
 *   date, time_start, time_end   ['meta' => string, 'format' => one of FieldFormats::DATE / ::TIME]
 *   weekday                      ['meta' => string, 'format' => one of FieldFormats::WEEKDAY]
 *   weekly_flag, cancelled_flag,
 *   vacation_flag                ['meta' => string, 'on' => ?string, 'off' => ?string]  (null = not stored)
 *   info_text, room              ['meta' => string, 'format' => 'text'|'html']
 *   any key                      ['none' => true]  the tool has no such field
 *
 * Every entry may carry 'companion' => [metaKey => value]: extra keys written with the value
 * (for example a field-reference key that some field frameworks keep next to the value).
 * Nothing in this class knows the tool's real field names.
 */
final class FieldMap
{
    public const FIELDS = ['date', 'time_start', 'time_end', 'weekly_flag', 'weekday', 'cancelled_flag', 'vacation_flag', 'info_text', 'room'];
    public const REQUIRED = ['date', 'time_start', 'time_end', 'weekly_flag', 'cancelled_flag', 'vacation_flag'];
    public const FLAGS = ['weekly_flag', 'cancelled_flag', 'vacation_flag'];

    /** @param array<string, mixed> $map */
    public function __construct(private readonly array $map)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->map;
    }

    /** @return array<string, mixed>|null the entry, or null if not learned or not present in the tool */
    public function entry(string $field): ?array
    {
        $e = $this->map[$field] ?? null;
        if (!is_array($e) || !empty($e['none']) || empty($e['meta'])) {
            return null;
        }

        return $e;
    }

    public function isLearned(string $field): bool
    {
        return isset($this->map[$field]) && is_array($this->map[$field]);
    }

    public function isComplete(): bool
    {
        foreach (self::REQUIRED as $f) {
            if ($this->entry($f) === null) {
                return false;
            }
        }
        foreach (self::FIELDS as $f) {
            if (!$this->isLearned($f)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Meta values to write for the given logical values. A null value deletes the meta key.
     * Logical values: date 'Y-m-d', times 'H:i' or null, weekday ISO int, flags bool, texts string.
     *
     * @param array<string, mixed> $values only the fields to write
     * @return array<string, ?string> meta key => value (null = delete)
     */
    public function encode(array $values): array
    {
        $out = [];
        // Flags first, grouped by meta key: several flags may share one key (one value per record).
        $flagKeys = [];
        foreach (self::FLAGS as $flag) {
            if (!array_key_exists($flag, $values) || ($e = $this->entry($flag)) === null) {
                continue;
            }
            $flagKeys[$e['meta']][] = [$flag, (bool) $values[$flag], $e];
        }
        foreach ($flagKeys as $meta => $flags) {
            $value = null;
            $set = false;
            foreach ($flags as [, $on, $e]) {
                if ($on) {
                    $value = $e['on'];
                    $set = true;
                    break;
                }
            }
            if (!$set) {
                $value = $flags[0][2]['off'];
            }
            $out[$meta] = $value === null ? null : (string) $value;
            foreach ($flags as [, , $e]) {
                $out += $this->companions($e, $value !== null);
            }
        }
        foreach ($values as $field => $value) {
            if (in_array($field, self::FLAGS, true) || ($e = $this->entry($field)) === null) {
                continue;
            }
            $encoded = null;
            if ($value !== null && $value !== '') {
                $encoded = match ($field) {
                    'date' => FieldFormats::encodeDate((string) $value, (string) $e['format']),
                    'time_start', 'time_end' => FieldFormats::encodeTime((string) $value, (string) $e['format']),
                    'weekday' => FieldFormats::encodeWeekday((int) $value, (string) $e['format']),
                    default => ($e['format'] ?? 'text') === 'html' ? (string) $value : wp_strip_all_tags((string) $value),
                };
            }
            $out[$e['meta']] = $encoded;
            $out += $this->companions($e, $encoded !== null);
            // Further keys that hold the same value in another format (learned together).
            foreach ((array) ($e['also'] ?? []) as $also) {
                if (!is_array($also) || empty($also['meta'])) {
                    continue;
                }
                $out[(string) $also['meta']] = $value === null || $value === '' ? null : match ($field) {
                    'date' => FieldFormats::encodeDate((string) $value, (string) $also['format']),
                    'time_start', 'time_end' => FieldFormats::encodeTime((string) $value, (string) $also['format']),
                    'weekday' => FieldFormats::encodeWeekday((int) $value, (string) $also['format']),
                    default => (string) $value,
                };
            }
        }

        return $out;
    }

    /**
     * Logical values read from a record's meta (for adoption and comparisons).
     *
     * @return array<string, mixed> date, time_start, time_end, weekday, weekly_flag, cancelled_flag, vacation_flag, info_text, room
     */
    public function decode(int $postId): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $e = $this->entry($field);
            if ($e === null) {
                $out[$field] = null;
                continue;
            }
            $exists = metadata_exists('post', $postId, $e['meta']);
            $raw = $exists ? (string) get_post_meta($postId, $e['meta'], true) : null;
            $out[$field] = match ($field) {
                'date' => $raw === null ? null : FieldFormats::decodeDate($raw, (string) $e['format']),
                'time_start', 'time_end' => $raw === null ? null : FieldFormats::decodeTime($raw, (string) $e['format']),
                'weekday' => $raw === null ? null : FieldFormats::decodeWeekday($raw, (string) $e['format']),
                'weekly_flag', 'cancelled_flag', 'vacation_flag' => $e['on'] === null ? !$exists : ($raw !== null && $raw === (string) $e['on']),
                default => $raw,
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, ?string>
     */
    private function companions(array $e, bool $present): array
    {
        $out = [];
        foreach ((array) ($e['companion'] ?? []) as $key => $value) {
            $out[(string) $key] = $present ? (string) $value : null;
        }

        return $out;
    }
}
