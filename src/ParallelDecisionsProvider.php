<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Providers\Concerns\Classifies;
use Laravel\Ai\Providers\Concerns\HasClassificationGateway;
use Laravel\Ai\Providers\Provider;

/**
 * A Laravel AI SDK classification provider backed by Parallel Decisions.
 *
 * Parallel has no models. The SDK model `decisions-fast` selects the
 * Decisions `fast` mode, the only mode Parallel offers, and matches the
 * `parallel:decisions-fast` price in Laravel AI Pricing.
 */
class ParallelDecisionsProvider extends Provider implements ClassificationProvider
{
    use Classifies;
    use HasClassificationGateway;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * @return array{key: mixed}
     */
    public function providerCredentials(): array
    {
        return ['key' => $this->config['key'] ?? null];
    }

    public function defaultClassificationModel(): string
    {
        $model = data_get($this->config, 'models.classification.default');

        return is_string($model) ? $model : ParallelDecisionsGateway::MODEL_FAST;
    }

    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= new ParallelDecisionsGateway;
    }
}
