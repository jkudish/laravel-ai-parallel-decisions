<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions\Exceptions;

use InvalidArgumentException;

/**
 * The request breaks a documented Decisions limit and was not sent.
 */
final class InvalidDecisionRequest extends InvalidArgumentException {}
