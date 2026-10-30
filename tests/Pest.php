<?php

declare(strict_types=1);

use Jkudish\LaravelAiParallelDecisions\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * @return array<string, mixed>
 */
function documentedLiveResponse(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/documented-live-response.json'), true, flags: JSON_THROW_ON_ERROR);
}
