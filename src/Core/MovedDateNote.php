<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * The one-line note of R-24 at the top of a weekly offer's body text.
 *
 * Markup: `<!-- enteronce:note --><p class="enteronce-note">…</p><!-- /enteronce:note -->`.
 * {@see MovedDateNote::strip()} removes it by the comment markers, and as a fallback
 * by the paragraph class, so the shell can also put the note on top of a body it
 * keeps from the site (adopted records, `_enteronce_keep_text`).
 */
final class MovedDateNote
{
    public const OPEN = '<!-- enteronce:note -->';
    public const CLOSE = '<!-- /enteronce:note -->';

    /**
     * German note text for a changed planned date, or null when nothing a visitor
     * needs to know changed (same day, time and room).
     *
     * Wording (examples):
     *  - «Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal»
     *  - «Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr»
     *  - «Am 7. Dezember ausnahmsweise im Raum Grosser Saal»
     *  - «Statt am 7. Dezember ausnahmsweise am Dienstag, 8. Dezember, 10:00–11:00 Uhr»
     *    (plus « im Raum …» when the room changed too)
     */
    public static function text(Slot $slot, string $regularRoom, bool $allDay): ?string
    {
        if ($slot->actualStart === null || $slot->actualEnd === null) {
            return null;
        }
        $room = $slot->fields?->room ?? '';
        $roomChanged = $room !== '' && $room !== $regularRoom;
        $sameDay = $slot->actualStart->format('Y-m-d') === $slot->originalDate;
        $timeChanged = $slot->actualStart != $slot->originalStart || $slot->actualEnd != $slot->originalEnd;
        $time = $allDay ? '' : $slot->actualStart->format('H:i') . '–' . $slot->actualEnd->format('H:i') . ' Uhr';
        $roomPart = $roomChanged ? 'im Raum ' . $room : '';

        if (!$sameDay) {
            $text = 'Statt am ' . GermanDate::dayMonth($slot->originalStart) . ' ausnahmsweise am ' . GermanDate::weekdayDayMonth($slot->actualStart);
            if ($time !== '') {
                $text .= ', ' . $time;
            }

            return $roomPart === '' ? $text : $text . ' ' . $roomPart;
        }
        if (!$timeChanged && !$roomChanged) {
            return null;
        }
        $parts = array_values(array_filter([$timeChanged ? $time : '', $roomPart], static fn ($p) => $p !== ''));

        return 'Am ' . GermanDate::dayMonth($slot->originalStart) . ' ausnahmsweise ' . implode(' ', $parts);
    }

    public static function html(string $note): string
    {
        return self::OPEN . '<p class="enteronce-note">' . htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' . self::CLOSE;
    }

    /** Body with the note (if any) on top and any previous note removed. */
    public static function apply(string $body, ?string $note): string
    {
        $clean = self::strip($body);

        return $note === null || $note === '' ? $clean : self::html($note) . $clean;
    }

    /** Removes every note this plugin put into a body. */
    public static function strip(string $body): string
    {
        $out = (string) preg_replace('/' . preg_quote(self::OPEN, '/') . '.*?' . preg_quote(self::CLOSE, '/') . '\s*/s', '', $body);

        return (string) preg_replace('/<p class="enteronce-note">.*?<\/p>\s*/s', '', $out);
    }
}
