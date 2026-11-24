<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use EnterOnce\Drift\FetchError;
use EnterOnce\Drift\PageFetcher;
use EnterOnce\Drift\PageResponse;

/** A live-looking fetcher that never touches the network: answers from a script and records every URL. */
final class FakeFetcher implements PageFetcher
{
    /** @var list<string> */
    public array $urls = [];

    /** @param list<PageResponse|FetchError> $script answers in order; the last one repeats */
    public function __construct(private array $script, private readonly bool $live = true)
    {
    }

    public static function ok(string $html): PageResponse
    {
        return new PageResponse(200, $html);
    }

    public function fetch(string $url): PageResponse
    {
        $this->urls[] = $url;
        $answer = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
        if ($answer instanceof FetchError) {
            throw $answer;
        }

        return $answer;
    }

    public function isLive(): bool
    {
        return $this->live;
    }
}
