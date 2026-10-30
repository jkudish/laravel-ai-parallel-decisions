<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions;

use Laravel\Ai\Responses\Data\UrlCitation;

/**
 * A web source in the evidence shared by every answer in one Decisions
 * request. Parallel does not map sources to individual answers.
 */
final class DecisionCitation extends UrlCitation
{
    /**
     * @param  list<string>  $excerpts  Passages included in the decision context, possibly shortened. Empty when excerpts were not requested.
     */
    public function __construct(
        string $url,
        ?string $title = null,
        public readonly array $excerpts = [],
    ) {
        parent::__construct($url, $title);
    }

    /**
     * @return array{url: string, title: string|null, start_index: int|null, end_index: int|null, excerpts: list<string>}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'start_index' => $this->startIndex,
            'end_index' => $this->endIndex,
            'excerpts' => $this->excerpts,
        ];
    }
}
