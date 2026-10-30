<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('source files declare strict types')
    ->expect('Jkudish\\LaravelAiParallelDecisions')
    ->toUseStrictTypes();

arch('the package has no persistence or UI concerns')
    ->expect('Jkudish\\LaravelAiParallelDecisions')
    ->not->toUse(['Illuminate\\Database', 'Illuminate\\View']);
