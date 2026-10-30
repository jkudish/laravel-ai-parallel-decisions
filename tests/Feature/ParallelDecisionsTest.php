<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiParallelDecisions\DecisionCitation;
use Jkudish\LaravelAiParallelDecisions\DecisionResponse;
use Jkudish\LaravelAiParallelDecisions\Exceptions\InvalidDecisionRequest;
use Jkudish\LaravelAiParallelDecisions\Exceptions\InvalidDecisionResponse;
use Jkudish\LaravelAiParallelDecisions\RefusalAnswer;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\Data\TextUsage;

const DECISIONS_URL = 'https://api.parallel.ai/v1/decisions';
const DECISION_ID = 'decisions_34b4be1b1405e19ba5516d9bb76c56ad';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * One question of each type, with asymmetric options so a swapped mapping fails.
 *
 * @return array<string, Question>
 */
function mixedQuestions(): array
{
    return [
        'is_operating' => new Boolean('Is Clay still operating?'),
        'has_cli' => new Boolean('Does Clay offer an official CLI?', ['true' => 'An official CLI is documented.', 'false' => 'Clay says no CLI exists.']),
        'employee_band' => new Choice('How many employees does Clay have?', ['small' => '1 to 100', 'mid' => '101 to 500', 'large' => '501 or more']),
        'cli_fit' => new Score('Does the CLI cover search and enrichment?', ['Neither', 'One of them', 'Both']),
    ];
}

/**
 * @return array<string, mixed>
 */
function mixedResponse(array $answers = []): array
{
    return [
        'mode' => 'fast',
        'answers' => [
            'is_operating' => ['type' => 'noul', 'noul' => 0.97],
            'has_cli' => ['type' => 'refusal'],
            'employee_band' => ['type' => 'choice', 'choice' => 'mid', 'probabilities' => ['small' => 0.05, 'mid' => 0.8, 'large' => 0.15], 'confidence' => 0.7],
            'cli_fit' => ['type' => 'score', 'score' => 1.7, 'legend' => ['0' => 'Neither', '1' => 'One of them', '2' => 'Both'], 'probabilities' => ['0' => 0.1, '1' => 0.1, '2' => 0.8]],
            ...$answers,
        ],
        'usage' => ['input_tokens' => 4212, 'output_tokens' => 0],
        'citations' => [
            ['url' => 'https://developers.clay.com/concepts/cli-basics', 'title' => 'CLI basics', 'excerpts' => ['The Clay CLI (clay) is JSON-first.']],
            ['url' => 'https://www.clay.com/agent-plugin', 'excerpts' => []],
        ],
        'decision_id' => DECISION_ID,
    ];
}

/**
 * The JSON body as sent on the wire.
 *
 * @return array<string, mixed>
 */
function sentBody(Request $request): array
{
    /** @var array<string, mixed> */
    return json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
}

function classifyMixed(): DecisionResponse
{
    $response = Classification::of(['company' => 'Clay', 'website' => 'https://www.clay.com'])
        ->questions(mixedQuestions())
        ->classify('parallel');

    expect($response)->toBeInstanceOf(DecisionResponse::class);
    assert($response instanceof DecisionResponse);

    return $response;
}

it('sends the exact Decisions body with the x-api-key header and no model field', function (): void {
    Http::fake([DECISIONS_URL => Http::response(mixedResponse())]);

    classifyMixed();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe(DECISIONS_URL)
            ->and($request->method())->toBe('POST')
            ->and($request->header('x-api-key'))->toBe(['synthetic-parallel-key'])
            ->and($request->hasHeader('Authorization'))->toBeFalse()
            ->and(sentBody($request))->toBe([
                'mode' => 'fast',
                'state' => ['company' => 'Clay', 'website' => 'https://www.clay.com'],
                'questions' => [
                    'is_operating' => ['type' => 'noul', 'instructions' => 'Is Clay still operating?'],
                    'has_cli' => ['type' => 'noul', 'instructions' => 'Does Clay offer an official CLI?', 'criteria' => ['true' => 'An official CLI is documented.', 'false' => 'Clay says no CLI exists.']],
                    'employee_band' => ['type' => 'choice', 'instructions' => 'How many employees does Clay have?', 'criteria' => ['small' => '1 to 100', 'mid' => '101 to 500', 'large' => '501 or more']],
                    'cli_fit' => ['type' => 'score', 'instructions' => 'Does the CLI cover search and enrichment?', 'criteria' => ['Neither', 'One of them', 'Both']],
                ],
            ]);

        return true;
    });
});

it('describes a choice option that has no description by its name', function (): void {
    Http::fake([DECISIONS_URL => Http::response([
        'mode' => 'fast',
        'answers' => ['pick' => ['type' => 'choice', 'choice' => 'b', 'probabilities' => ['a' => 0.25, 'b' => 0.75]]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        'citations' => [],
        'decision_id' => DECISION_ID,
    ])]);

    $answer = Classification::of('Clay')->question('pick', new Choice('Pick one', ['a' => null, 'b' => 'Option B']))->classify('parallel')->answer('pick');

    expect($answer instanceof ChoiceAnswer ? $answer->choice : null)->toBe('b');
    Http::assertSent(fn (Request $request): bool => sentBody($request)['questions']['pick']['criteria'] === ['a' => 'a', 'b' => 'Option B']);
});

it('hydrates typed answers, refusals, citations, usage and the decision id', function (): void {
    Http::fake([DECISIONS_URL => Http::response(mixedResponse())]);

    $response = classifyMixed();
    $operating = $response->answer('is_operating');
    $band = $response->answer('employee_band');
    $fit = $response->answer('cli_fit');
    $citations = $response->citations();

    expect($operating)->toBeInstanceOf(BooleanAnswer::class)
        ->and($operating instanceof BooleanAnswer ? $operating->probability : null)->toBe(0.97)
        ->and($response->answer('has_cli'))->toBeInstanceOf(RefusalAnswer::class)
        ->and($response->refusals())->toBe(['has_cli'])
        ->and($band)->toBeInstanceOf(ChoiceAnswer::class)
        ->and($band instanceof ChoiceAnswer ? [$band->choice, $band->probabilities, $band->confidence] : null)->toBe(['mid', ['small' => 0.05, 'mid' => 0.8, 'large' => 0.15], 0.7])
        ->and($fit)->toBeInstanceOf(ScoreAnswer::class)
        ->and($fit instanceof ScoreAnswer ? [$fit->score, $fit->probabilities, $fit->legend, $fit->level(), $fit->label()] : null)->toBe([1.7, [0 => 0.1, 1 => 0.1, 2 => 0.8], ['Neither', 'One of them', 'Both'], 2, 'Both'])
        ->and($response->decisionId)->toBe(DECISION_ID)
        ->and($response->usage->inputTokens)->toBe(4212)
        ->and($response->usage->outputTokens)->toBe(0)
        ->and($response->meta->provider)->toBe('parallel')
        ->and($response->meta->model)->toBe('decisions-fast')
        ->and($response->meta->citations)->toHaveCount(2)
        ->and($citations)->toHaveCount(2)
        ->and($citations[0])->toBeInstanceOf(DecisionCitation::class)
        ->and($citations[0]->toArray())->toBe(['url' => 'https://developers.clay.com/concepts/cli-basics', 'title' => 'CLI basics', 'start_index' => null, 'end_index' => null, 'excerpts' => ['The Clay CLI (clay) is JSON-first.']])
        ->and([$citations[1]->title, $citations[1]->excerpts])->toBe([null, []])
        ->and($response->toArray()['decision_id'])->toBe(DECISION_ID);
});

it('reads the live response Parallel documents for its quickstart', function (): void {
    Http::fake([DECISIONS_URL => Http::response(documentedLiveResponse())]);

    $response = Classification::of(['company' => 'Clay', 'website' => 'https://www.clay.com'])
        ->question('has_cli', new Boolean('Does Clay offer an official command-line interface (CLI)?'))
        ->classify('parallel');
    $answer = $response->answer('has_cli');

    expect($response)->toBeInstanceOf(DecisionResponse::class)
        ->and($answer instanceof BooleanAnswer ? $answer->probability : null)->toBe(0.9992643798509062)
        ->and($response->meta->citations->first()?->title)->toBe('CLI basics')
        ->and($response instanceof DecisionResponse ? $response->decisionId : null)->toBe(DECISION_ID);
});

it('passes include_excerpts through and honours a configured URL and headers', function (): void {
    config()->set('ai.providers.parallel.url', 'https://eu.parallel.example/');
    config()->set('ai.providers.parallel.headers', ['X-Trace' => 'abc']);
    Http::fake(['https://eu.parallel.example/v1/decisions' => Http::response(mixedResponse(['has_cli' => ['type' => 'noul', 'noul' => 0.5]]))]);

    Classification::of('Clay, https://www.clay.com')
        ->questions(mixedQuestions())
        ->withProviderOptions(['include_excerpts' => false])
        ->classify('parallel');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.parallel.example/v1/decisions'
        && $request->header('X-Trace') === ['abc']
        && sentBody($request)['include_excerpts'] === false
        && sentBody($request)['state'] === 'Clay, https://www.clay.com');
});

it('sends numeric question keys as a JSON object', function (): void {
    Http::fake([DECISIONS_URL => Http::response([
        'mode' => 'fast',
        'answers' => ['0' => ['type' => 'noul', 'noul' => 0.2], '1' => ['type' => 'noul', 'noul' => 0.8]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        'citations' => [],
        'decision_id' => DECISION_ID,
    ])]);

    $response = Classification::of('Clay')->question('0', new Boolean('First?'))->question('1', new Boolean('Second?'))->classify('parallel');
    $second = $response->answer('1');

    expect($second instanceof BooleanAnswer ? $second->probability : null)->toBe(0.8);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"questions":{"0":{"type":"noul","instructions":"First?"},"1":'));
});

it('validates a complete distribution whatever order its keys arrive in', function (array $probabilities): void {
    Http::fake([DECISIONS_URL => Http::response([
        'mode' => 'fast',
        'answers' => ['pick' => ['type' => 'choice', 'choice' => '1a', 'probabilities' => $probabilities]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        'citations' => [],
        'decision_id' => DECISION_ID,
    ])]);

    $answer = Classification::of('Clay')->question('pick', new Choice('Pick one', ['9.5' => 'Nine and a half', '10.5' => 'Ten and a half', '1a' => 'One A']))->classify('parallel')->answer('pick');

    expect($answer instanceof ChoiceAnswer ? $answer->choice : null)->toBe('1a');
})->with([
    'declared order' => [['9.5' => 0.2, '10.5' => 0.3, '1a' => 0.5]],
    'reversed' => [['1a' => 0.5, '10.5' => 0.3, '9.5' => 0.2]],
    'mixed' => [['10.5' => 0.3, '1a' => 0.5, '9.5' => 0.2]],
]);

it('accepts a confidence outside 0 to 1 because Parallel does not bound it', function (): void {
    Http::fake([DECISIONS_URL => Http::response(mixedResponse(['employee_band' => ['type' => 'choice', 'choice' => 'mid', 'probabilities' => ['small' => 0.05, 'mid' => 0.8, 'large' => 0.15], 'confidence' => 1.5]]))]);

    $band = classifyMixed()->answer('employee_band');

    expect($band instanceof ChoiceAnswer ? $band->confidence : null)->toBe(1.5);
});

it('dispatches Classified with the DecisionResponse so spend guards can settle', function (): void {
    Http::fake([DECISIONS_URL => Http::response(mixedResponse())]);
    Event::fake([Classified::class]);

    classifyMixed();

    Event::assertDispatched(Classified::class, fn (Classified $event): bool => $event->response instanceof DecisionResponse
        && $event->response->decisionId === DECISION_ID
        && $event->model === 'decisions-fast' && $event->response->meta->model === 'decisions-fast');
});

it('keeps laravel/ai fakes working, including a faked DecisionResponse', function (): void {
    $faked = new DecisionResponse(['flag' => new RefusalAnswer], new TextUsage, new Meta('parallel', 'decisions-fast'), DECISION_ID);
    Classification::fake([$faked]);

    $response = Classification::of('Clay')->question('flag', new Boolean('Is Clay operating?'))->classify('parallel');

    expect($response)->toBe($faked);
    Http::assertNothingSent();
    Classification::assertClassified(fn ($prompt): bool => isset($prompt->questions['flag']));
});

it('raises failoverable errors for rate limits and outages so a fallback provider can answer', function (int $status, string $exception): void {
    Http::fake([DECISIONS_URL => Http::response(['type' => 'error', 'error' => ['ref_id' => 'r', 'message' => 'busy']], $status)]);

    expect(fn () => classifyMixed())->toThrow($exception);
})->with([
    'rate limited' => [429, RateLimitedException::class],
    'unavailable' => [503, ProviderOverloadedException::class],
    'out of credits' => [402, InsufficientCreditsException::class],
]);

it('fails over on a connection error without leaking the API key', function (): void {
    Http::fake([DECISIONS_URL => fn () => throw new ConnectionException('cURL error 28: timed out')]);

    try {
        classifyMixed();
        $this->fail('Expected a connection exception.');
    } catch (ProviderConnectionException $exception) {
        expect($exception)->toBeInstanceOf(FailoverableException::class)
            ->and($exception->getMessage())->not->toContain('synthetic-parallel-key');
    }
});

it('fails over to the next provider in the list', function (): void {
    config()->set('ai.providers.parallel-backup', ['driver' => 'parallel', 'key' => 'backup-key', 'url' => 'https://backup.parallel.example']);
    Http::fake([
        DECISIONS_URL => Http::response([], 503),
        'https://backup.parallel.example/v1/decisions' => Http::response(mixedResponse()),
    ]);

    $response = Classification::of('Clay')->questions(mixedQuestions())->classify(['parallel', 'parallel-backup']);

    expect($response->meta->provider)->toBe('parallel-backup');
    Http::assertSentCount(2);
});

it('does not fail over on a validation error', function (): void {
    Http::fake([DECISIONS_URL => Http::response(['type' => 'error', 'error' => ['ref_id' => 'r', 'message' => 'Request validation error.']], 422)]);

    try {
        classifyMixed();
        $this->fail('Expected a request exception.');
    } catch (RequestException $exception) {
        expect($exception)->not->toBeInstanceOf(FailoverableException::class)
            ->and($exception->response->status())->toBe(422);
    }
});

it('refuses a request that breaks a documented limit before sending it', function (Closure $classify, string $message): void {
    Http::fake();

    expect($classify)->toThrow(InvalidDecisionRequest::class, $message);
    Http::assertNothingSent();
})->with([
    'more than 100 questions' => [fn () => Classification::of('Clay')->questions(array_combine(
        array_map(fn (int $i): string => "q{$i}", range(1, 101)),
        array_fill(0, 101, new Boolean('Is it true?')),
    ))->classify('parallel'), '1 to 100 questions per request; 101 given'],
    'question key with a space' => [fn () => Classification::of('Clay')->question('has cli', new Boolean('Is it true?'))->classify('parallel'), 'Question key [has cli]'],
    'structured instructions' => [fn () => Classification::of('Clay')->question('flag', new Boolean(['type' => 'image', 'url' => 'https://example.com/a.png']))->classify('parallel'), 'must be non-empty text'],
    'structured option description' => [fn () => Classification::of('Clay')->question('pick', new Choice('Pick one', ['a' => 'A', 'b' => ['nested' => true]]))->classify('parallel'), 'options must be non-empty text'],
    'choice with 256 options' => [fn () => Classification::of('Clay')->question('pick', new Choice('Pick one', array_combine(
        array_map(fn (int $i): string => "o{$i}", range(1, 256)),
        array_fill(0, 256, 'An option'),
    )))->classify('parallel'), 'has 256 options; Parallel Decisions accepts at most 255'],
    'score with 11 levels' => [fn () => Classification::of('Clay')->question('rate', new Score('Rate it', array_fill(0, 11, 'A level')))->classify('parallel'), 'has 11 levels; Parallel Decisions accepts at most 10'],
    'unknown model' => [fn () => Classification::of('Clay')->question('flag', new Boolean('Is it true?'))->classify('parallel', 'pro'), 'no model [pro]'],
    'wire mode as the model' => [fn () => Classification::of('Clay')->question('flag', new Boolean('Is it true?'))->classify('parallel', 'fast'), 'no model [fast]'],
    'boolean criteria with only the true case' => [fn () => Classification::of('Clay')->question('flag', new Boolean('Is it true?', ['true' => 'Yes']))->classify('parallel'), 'both the true and the false case'],
    'empty boolean criteria' => [fn () => Classification::of('Clay')->question('flag', new Boolean('Is it true?', []))->classify('parallel'), 'both the true and the false case'],
    'unknown provider option' => [fn () => Classification::of('Clay')->question('flag', new Boolean('Is it true?'))->withProviderOptions(['model' => 'x'])->classify('parallel'), 'does not accept the provider options: model'],
    'non-boolean include_excerpts' => [fn () => Classification::of('Clay')->question('flag', new Boolean('Is it true?'))->withProviderOptions(['include_excerpts' => 'no'])->classify('parallel'), 'include_excerpts provider option must be a boolean'],
]);

it('refuses to send without an API key', function (): void {
    config()->set('ai.providers.parallel', ['driver' => 'parallel']);
    Http::fake();

    expect(fn () => classifyMixed())->toThrow(InvalidArgumentException::class, 'needs an API key');
    Http::assertNothingSent();
});

it('rejects a response that breaks the contract for the questions asked', function (Closure $mutate, string $rule, ?string $question): void {
    $body = mixedResponse();
    $mutate($body);
    Http::fake([DECISIONS_URL => Http::response($body)]);

    try {
        classifyMixed();
        $this->fail('Expected an invalid response.');
    } catch (InvalidDecisionResponse $exception) {
        expect([$exception->rule, $exception->question])->toBe([$rule, $question])
            ->and($exception)->not->toBeInstanceOf(FailoverableException::class);
    }
})->with([
    'missing answer' => [function (array &$body): void {
        unset($body['answers']['cli_fit']);
    }, 'answer_missing', 'cli_fit'],
    'unrequested answer' => [function (array &$body): void {
        $body['answers']['extra'] = ['type' => 'noul', 'noul' => 0.5];
    }, 'answer_unexpected', 'extra'],
    'choice answer to a boolean question' => [function (array &$body): void {
        $body['answers']['is_operating'] = $body['answers']['employee_band'];
    }, 'answer_type_mismatch', 'is_operating'],
    'probability above one' => [function (array &$body): void {
        $body['answers']['is_operating']['noul'] = 1.2;
    }, 'noul_probability', 'is_operating'],
    'probability as a string' => [function (array &$body): void {
        $body['answers']['is_operating']['noul'] = '0.9';
    }, 'noul_probability', 'is_operating'],
    'choice outside the options' => [function (array &$body): void {
        $body['answers']['employee_band']['choice'] = 'huge';
    }, 'choice_value', 'employee_band'],
    'probabilities missing an option' => [function (array &$body): void {
        $body['answers']['employee_band']['probabilities'] = ['small' => 0.2, 'mid' => 0.8];
    }, 'probability_keys', 'employee_band'],
    'probabilities that do not sum to one' => [function (array &$body): void {
        $body['answers']['employee_band']['probabilities']['large'] = 0.5;
    }, 'probability_sum', 'employee_band'],
    'confidence as a string' => [function (array &$body): void {
        $body['answers']['employee_band']['confidence'] = 'high';
    }, 'confidence_value', 'employee_band'],
    'score above the top level' => [function (array &$body): void {
        $body['answers']['cli_fit']['score'] = 2.5;
    }, 'score_value', 'cli_fit'],
    'score probabilities for a missing level' => [function (array &$body): void {
        $body['answers']['cli_fit']['probabilities'] = ['0' => 0.5, '1' => 0.5];
    }, 'probability_keys', 'cli_fit'],
    'citation without a URL' => [function (array &$body): void {
        $body['citations'][] = ['title' => 'No URL', 'excerpts' => []];
    }, 'citations_invalid', null],
    'citation excerpts as text' => [function (array &$body): void {
        $body['citations'][0]['excerpts'] = 'one string';
    }, 'citations_invalid', null],
    'unknown mode' => [function (array &$body): void {
        $body['mode'] = 'deep';
    }, 'mode_invalid', null],
    'missing usage' => [function (array &$body): void {
        unset($body['usage']);
    }, 'usage_invalid', null],
    'missing decision id' => [function (array &$body): void {
        unset($body['decision_id']);
    }, 'decision_id_missing', null],
]);

it('keeps the decision id and usage on an invalid response so the call can still be settled', function (): void {
    Http::fake([DECISIONS_URL => Http::response(mixedResponse(['employee_band' => ['type' => 'choice', 'choice' => 'huge', 'probabilities' => ['small' => 0.05, 'mid' => 0.8, 'large' => 0.15]]]))]);

    try {
        classifyMixed();
        $this->fail('Expected an invalid response.');
    } catch (InvalidDecisionResponse $exception) {
        expect($exception->decisionId)->toBe(DECISION_ID)
            ->and($exception->usage?->inputTokens)->toBe(4212)
            ->and($exception->getMessage())->toBe('Parallel Decisions returned an invalid response (choice_value) for question [employee_band].');
    }
});
