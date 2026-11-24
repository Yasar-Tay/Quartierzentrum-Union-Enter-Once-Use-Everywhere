<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/** Reads a saved copy of the page. Makes no request; used by tests and trial runs. */
final class FilePageFetcher implements PageFetcher
{
    public function __construct(private readonly string $file)
    {
    }

    public function fetch(string $url): PageResponse
    {
        $html = is_file($this->file) ? @file_get_contents($this->file) : false;
        if ($html === false) {
            throw new FetchError('Die Seitendatei kann nicht gelesen werden: ' . $this->file);
        }

        return new PageResponse(200, $html);
    }

    public function isLive(): bool
    {
        return false;
    }
}
