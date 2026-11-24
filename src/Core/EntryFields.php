<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use Sabre\VObject\Component\VEvent;

/**
 * The text fields of one calendar entry (a single event, a series master or one
 * changed date of a series), after cancellation rule, clean-up and mapping.
 */
final class EntryFields
{
    /**
     * @param list<string> $categories canonical calendar category names
     * @param list<string> $tags canonical calendar tag names
     * @param list<array{kind: string, field: string, value: string, corrected_to: ?string}> $issues mapping issues
     */
    public function __construct(
        public readonly string $title,
        public readonly bool $cancelled,
        public readonly string $room,
        public readonly string $location,
        public readonly string $description,
        public readonly string $descriptionHtml,
        public readonly array $categories,
        public readonly array $tags,
        public readonly array $issues,
    ) {
    }

    public static function fromComponent(VEvent $event, Settings $settings, Mapper $mapper): self
    {
        [$title, $cancelled] = CancelRule::apply(
            PlainText::line(isset($event->SUMMARY) ? (string) $event->SUMMARY : ''),
            isset($event->STATUS) ? (string) $event->STATUS : '',
            $settings->cancelPrefixes
        );
        $location = PlainText::line(isset($event->LOCATION) ? (string) $event->LOCATION : '');
        $room = trim(explode(',', $location, 2)[0]);
        $parsed = DescriptionParser::parse(isset($event->DESCRIPTION) ? (string) $event->DESCRIPTION : '');
        $cats = $mapper->resolve($parsed['categories'], 'category');
        $tags = $mapper->resolve($parsed['tags'], 'tag');
        $issues = [];
        foreach ($cats['issues'] as $issue) {
            $issues[] = $issue + ['field' => 'category'];
        }
        foreach ($tags['issues'] as $issue) {
            $issues[] = $issue + ['field' => 'tag'];
        }

        return new self(
            $title,
            $cancelled,
            $room,
            $location,
            $parsed['text'],
            $parsed['html'],
            $cats['values'],
            $tags['values'],
            $issues,
        );
    }

    /**
     * Notices for this entry (section 4.5). None for a cancelled entry.
     *
     * @return list<Notice>
     */
    public function notices(string $sourceId, string $uid): array
    {
        if ($this->cancelled) {
            return [];
        }
        $out = [];
        foreach ($this->issues as $issue) {
            $label = $issue['field'] === 'tag' ? 'Zielgruppe (tag)' : 'Kategorie (event-type)';
            if ($issue['kind'] === 'corrected') {
                $out[] = new Notice(
                    Notice::WARNING,
                    Notice::CODE_VALUE_CORRECTED,
                    $sourceId,
                    $uid,
                    $this->title,
                    sprintf('«%s»: unbekannte %s «%s» wurde zu «%s» korrigiert.', $this->title, $label, $issue['value'], (string) $issue['corrected_to']),
                    $issue['value'],
                    $issue['corrected_to'],
                    $issue['field'],
                );
            } else {
                $out[] = new Notice(
                    Notice::WARNING,
                    Notice::CODE_VALUE_DROPPED,
                    $sourceId,
                    $uid,
                    $this->title,
                    sprintf('«%s»: unbekannte %s «%s» wurde nicht übernommen.', $this->title, $label, $issue['value']),
                    $issue['value'],
                    null,
                    $issue['field'],
                );
            }
        }
        if ($this->categories === []) {
            $out[] = new Notice(
                Notice::INFO,
                Notice::CODE_NO_CATEGORY,
                $sourceId,
                $uid,
                $this->title,
                sprintf('«%s» hat keine Kategorie.', $this->title),
            );
        }

        return $out;
    }
}
