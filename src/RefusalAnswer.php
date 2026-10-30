<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions;

use Laravel\Ai\Responses\Data\Answer;

/**
 * Parallel declined to answer this question. It carries no value or
 * probabilities: treat it as its own outcome, never as a negative predicate,
 * the first choice, or a zero score.
 */
final class RefusalAnswer extends Answer
{
    /**
     * @return array{refusal: true}
     */
    public function toArray(): array
    {
        return ['refusal' => true];
    }
}
