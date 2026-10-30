<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;

/**
 * Registers the `parallel` driver with the Laravel AI SDK, so a provider
 * configured as `['driver' => 'parallel', 'key' => ...]` answers
 * Classification::of(...)->classify() through Parallel Decisions.
 */
final class ParallelDecisionsServiceProvider extends ServiceProvider
{
    public const string DRIVER = 'parallel';

    public function register(): void
    {
        $this->callAfterResolving(AiManager::class, static function (AiManager $manager): void {
            $manager->extend(self::DRIVER, function (Application $app, array $config): ParallelDecisionsProvider {
                /** @var array<string, mixed> $config */
                return new ParallelDecisionsProvider($config, $app->make(Dispatcher::class));
            });
        });
    }
}
