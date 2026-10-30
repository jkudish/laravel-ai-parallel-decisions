<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions;

use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * A classification response from Parallel Decisions. Citations live in
 * `$response->meta->citations`, which also works for a faked response.
 */
class DecisionResponse extends ClassificationResponse
{
    /**
     * @param  array<string, Answer>  $answers
     */
    public function __construct(
        array $answers,
        TextUsage $usage,
        Meta $meta,
        public readonly string $decisionId,
    ) {
        parent::__construct($answers, $usage, $meta);
    }

    /**
     * The web sources shared by every answer in this request.
     *
     * @return list<DecisionCitation>
     */
    public function citations(): array
    {
        return array_values($this->meta->citations
            ->filter(static fn (mixed $citation): bool => $citation instanceof DecisionCitation)
            ->all());
    }

    /**
     * Keys of the questions Parallel refused to answer.
     *
     * @return list<string>
     */
    public function refusals(): array
    {
        return array_keys(array_filter($this->answers, static fn (Answer $answer): bool => $answer instanceof RefusalAnswer));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answers' => $this->answers,
            'usage' => $this->usage,
            'meta' => $this->meta,
            'decision_id' => $this->decisionId,
        ];
    }
}
