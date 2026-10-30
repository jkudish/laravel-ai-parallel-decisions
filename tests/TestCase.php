<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions\Tests;

use Illuminate\Foundation\Application;
use Jkudish\LaravelAiParallelDecisions\ParallelDecisionsServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, ParallelDecisionsServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.parallel', ['driver' => 'parallel', 'key' => 'synthetic-parallel-key']);
    }
}
