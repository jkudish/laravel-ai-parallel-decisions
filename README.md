<h1 align="center">Laravel AI Parallel Decisions</h1>

<p align="center">
  <strong>Web-grounded classification for the Laravel AI SDK.</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/jkudish/laravel-ai-parallel-decisions"><img src="https://img.shields.io/packagist/v/jkudish/laravel-ai-parallel-decisions" alt="Latest version"></a>
  <a href="https://github.com/jkudish/laravel-ai-parallel-decisions/actions/workflows/run-tests.yml"><img src="https://github.com/jkudish/laravel-ai-parallel-decisions/actions/workflows/run-tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/jkudish/laravel-ai-parallel-decisions/actions/workflows/quality.yml"><img src="https://github.com/jkudish/laravel-ai-parallel-decisions/actions/workflows/quality.yml/badge.svg" alt="Quality"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/github/license/jkudish/laravel-ai-parallel-decisions" alt="License"></a>
</p>

This package adds [Parallel Decisions](https://docs.parallel.ai/decisions/decisions-quickstart) as a `parallel` provider for `Classification::of()` in the [Laravel AI SDK](https://github.com/laravel/ai). You ask the same Boolean, Choice and Score questions you would ask Jev. Parallel answers from current web evidence and returns the pages it used.

The package is pre-1.0. Minor releases may change the public API until 1.0.0; the [changelog](CHANGELOG.md) records each change.

Use it when the answer depends on facts that change: whether a company still operates, its employee band, whether a product has a feature, whether a list still runs this year. Use a model such as Jev when the answer should come only from the state you supply.

## Requirements

- PHP 8.4 or newer
- Laravel 12 or 13
- `laravel/ai` 1.0.1 or newer
- A Parallel API key from [platform.parallel.ai](https://platform.parallel.ai)

## Installation

```bash
composer require jkudish/laravel-ai-parallel-decisions
```

Laravel discovers the service provider. Add a provider to `config/ai.php`:

```php
'providers' => [
    // ...
    'parallel' => [
        'driver' => 'parallel',
        'key' => env('PARALLEL_API_KEY'),
    ],
],
```

## Ask a question

Each `classify('parallel')` call sends your state and questions to Parallel and is one paid request (USD 0.001; see [Cost](#cost)). It returns in about a second.

```php
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;

$response = Classification::of(['company' => 'Clay', 'website' => 'https://www.clay.com'])
    ->question('has_cli', new Boolean('Does Clay offer an official command-line interface?'))
    ->question('employee_band', new Choice('How many employees does Clay have?', [
        'small' => '1 to 100',
        'mid' => '101 to 500',
        'large' => '501 or more',
        'unknown' => 'The evidence does not establish the employee count.',
    ]))
    ->classify('parallel');

$response->answer('has_cli')->probability;   // 0.998
$response->answer('employee_band')->choice;  // "mid"

foreach ($response->meta->citations as $citation) {
    $citation->url;       // "https://developers.clay.com/quickstart"
    $citation->title;     // "Quickstart - Clay docs", or null
    $citation->excerpts;  // Passages Parallel used as evidence
}

$response->decisionId;   // "decisions_d49c01f3..."
```

All questions in one call share one set of web evidence. Group questions about the same company or topic; send unrelated entities in separate calls. One call takes up to 100 questions.

## What you get back

`classify('parallel')` returns a `DecisionResponse`, a subclass of the SDK's `ClassificationResponse`:

| Question | Answer | Value |
| --- | --- | --- |
| `Boolean` | `BooleanAnswer` | `probability` that the statement is true |
| `Choice` | `ChoiceAnswer` | `choice`, `probabilities` per option, `confidence` or null |
| `Score` | `ScoreAnswer` | `score` (probability-weighted mean level), `probabilities`, `legend` from your levels |
| any | `RefusalAnswer` | Parallel declined to answer this question |

Citations are `DecisionCitation` objects in `$response->meta->citations`. They cover the whole call. Parallel does not say which source supports which answer, so ask one question per call when you need a source for a specific answer.

### Handle refusals

Any question can come back as a `RefusalAnswer`. It has no value or probabilities. Do not read it as false, the first option or a zero score.

```php
use Jkudish\LaravelAiParallelDecisions\RefusalAnswer;

if ($response->answer('has_cli') instanceof RefusalAnswer) {
    // Review by hand, or ask again later.
}

$response->refusals(); // ["has_cli"]
```

The SDK's `decide()` helpers on `Str`, `Stringable` and collections expect a concrete answer and throw on a refusal. Call `Classification::of()` directly when a refusal is possible.

### Check confidence before you act

Refusals are rare. In testing in October 2026, Parallel answered every question written to provoke one:

- A Choice about a share price in 2031 came back as a 51/49 split with `confidence` 0.02.
- A Boolean about whether a stolen password would work came back with `probability` 0.52.
- A Choice about the street a CEO lives on picked an answer with `confidence` 0.54.

A missing refusal does not mean the evidence was good. Before acting on an answer:

- Set a minimum `confidence` for `Choice` and `Score` answers, and treat anything below it as unknown.
- Treat a Boolean `probability` near 0.5 as unknown. Booleans have no `confidence`.
- Give a `Choice` an explicit `unknown` option, as in the example above.
- Do not count on a refusal to block questions you should not ask, such as questions about private people.

## Validation

The package checks requests before sending them and refuses, with `InvalidDecisionRequest`, when:

- a call has fewer than 1 or more than 100 questions;
- a question key contains anything other than ASCII letters, numbers, `_` or `-`;
- a choice has more than 255 options, or a score more than 10 levels;
- Boolean criteria describe only one of the true and false cases;
- instructions, criteria, options or levels are not plain text (Parallel accepts no images or structured content);
- the model is anything but `decisions-fast`, or a provider option other than `include_excerpts` is passed.

Parallel also limits each question, with the shared state, to about 32,000 input tokens and 5,000 rendered characters. Parallel estimates those itself, so the package leaves them to the API.

Responses are checked against the questions you asked. `InvalidDecisionResponse` is thrown when an answer is missing or unexpected, has the wrong type, picks an option you did not offer, or has probabilities outside 0 to 1 or not summing to 1. The exception's `rule` names the check, and `decisionId` and `usage` are set when Parallel reported them, so you can still account for a call that was sent.

## Options

| Option | How to set it | Default |
| --- | --- | --- |
| Model | `classify('parallel', 'decisions-fast')` | `decisions-fast`, sent as Parallel's `fast` mode, the only mode it offers |
| Excerpts | `->withProviderOptions(['include_excerpts' => false])` | `true`. With `false`, citations keep their URL and title but have no excerpts |
| Timeout | `->timeout(15)` | 30 seconds |
| Base URL | `'url' => '...'` in the provider config | `https://api.parallel.ai` |
| Extra headers | `'headers' => [...]` in the provider config, or `->withHeaders([...])` | none |

## Failover

Rate limits (HTTP 429), exhausted credits (402) and outages (502, 503, 504, 520, 522, 524) raise the SDK's failover exceptions, so a provider list falls through:

```php
Classification::of($state)->questions($questions)->classify(['parallel', 'typesafe']);
```

Connection errors and timeouts fail over too. Since `laravel/ai` 1.2.1 the SDK also retries once on 429 and 5xx when `Retry-After` is 10 seconds or less.

Keep these in mind:

- The fallback answers from its own training data, not the web. Check `$response->meta->provider` if that matters.
- A timeout can happen after Parallel has billed the call, so failing over can add a second charge.
- Provider options go to every provider in the list. Scope them to Parallel with a closure:

```php
use Laravel\Ai\Providers\Provider;

->withProviderOptions(fn (Provider $provider) => $provider->driver() === 'parallel' ? ['include_excerpts' => false] : [])
```

A 422 validation error or an invalid response does not fail over.

## Cost

Parallel bills USD 1 per 1,000 requests, and one request carries up to 100 questions. See [Parallel pricing](https://docs.parallel.ai/getting-started/pricing). Each call is a paid request that reaches the web.

The SDK's `Classified` event carries the `DecisionResponse`, so listeners can record `decisionId` and usage. The model is `decisions-fast` in the `Classifying` and `Classified` events and in `$response->meta->model`, the same name [Laravel AI Pricing](https://github.com/jkudish/laravel-ai-pricing) 0.4.3 or newer prices it under. Quote one request:

```php
use Jkudish\LaravelAiPricing\Facades\AiPricing;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

AiPricing::quote('parallel', 'decisions-fast', new Usage(['requests' => 1])); // USD 0.001
```

The reported token counts are not billed, so do not pass them to the quote.

## Testing

The SDK's fakes work unchanged. Return a `DecisionResponse` when your code reads `decisionId`:

```php
use Jkudish\LaravelAiParallelDecisions\DecisionResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

Classification::fake([
    new DecisionResponse(['has_cli' => new BooleanAnswer(0.9)], new TextUsage, new Meta('parallel', 'decisions-fast'), 'decisions_test'),
]);
```

A plain faked response has no `decisionId`, but `$response->meta->citations` works either way.

## Development

```bash
composer install
composer test
composer analyse
composer lint:check
```

Tests fake every HTTP request and never call Parallel.

## Sponsoring

If you find Laravel AI Parallel Decisions useful, consider becoming a [sponsor](https://github.com/sponsors/jkudish) or [donating](https://stripe.com/@jkudish).

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md) and the [changelog](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
