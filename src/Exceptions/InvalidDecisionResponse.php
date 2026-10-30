<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions\Exceptions;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Parallel answered, but the response does not match the documented
 * contract for the questions asked. The request was sent and may be billed:
 * `decisionId` and `usage` are set when the response reported them, so a
 * spend guard can still settle the call. This is not a failover error;
 * retrying the same request elsewhere would hide the defect.
 */
final class InvalidDecisionResponse extends AiException
{
    public function __construct(
        public readonly string $rule,
        public readonly ?string $question = null,
        public readonly ?string $decisionId = null,
        public readonly ?TextUsage $usage = null,
    ) {
        parent::__construct(sprintf(
            'Parallel Decisions returned an invalid response (%s)%s.',
            $rule,
            $question === null ? '' : " for question [{$question}]",
        ));
    }
}
