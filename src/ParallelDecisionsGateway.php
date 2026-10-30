<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiParallelDecisions;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Jkudish\LaravelAiParallelDecisions\Exceptions\InvalidDecisionRequest;
use Jkudish\LaravelAiParallelDecisions\Exceptions\InvalidDecisionResponse;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Answers Laravel AI SDK classification questions through
 * `POST https://api.parallel.ai/v1/decisions`.
 *
 * Boolean, Choice and Score questions map to Parallel's `noul`, `choice` and
 * `score` types. Requests that break a documented limit are refused before
 * dispatch. Responses are validated against the questions asked: every
 * question has exactly one answer of its own type, probabilities lie in
 * [0, 1] and sum to 1, and a refusal becomes a RefusalAnswer.
 *
 * @see https://docs.parallel.ai/api-reference/decisions/make-web-grounded-typed-decisions
 */
class ParallelDecisionsGateway implements ClassificationGateway
{
    use CreatesClient;
    use HandlesFailoverErrors;

    public const string URL = 'https://api.parallel.ai';

    public const string ENDPOINT = '/v1/decisions';

    /**
     * The SDK model name, matching the `parallel:decisions-fast` price in
     * Laravel AI Pricing. Requests send it as Parallel's `fast` mode.
     */
    public const string MODEL_FAST = 'decisions-fast';

    /** @var array<string, string> SDK model name => Parallel `mode`. */
    public const array MODELS = [self::MODEL_FAST => 'fast'];

    public const int MAX_QUESTIONS = 100;

    public const int MAX_CHOICE_OPTIONS = 255;

    public const int MAX_SCORE_LEVELS = 10;

    /** Provider options Parallel accepts beside mode, state and questions. */
    public const array PROVIDER_OPTIONS = ['include_excerpts'];

    /** How far a probability distribution may stray from summing to 1. */
    public const float PROBABILITY_TOLERANCE = 0.01;

    /**
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @param  array<int|string, mixed>  $attachments  Accepted for laravel/ai 1.1+ compatibility; Parallel takes no attachments.
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): DecisionResponse {
        if ($attachments !== []) {
            throw new InvalidDecisionRequest('Parallel Decisions accepts no attachments; put text evidence in the state.');
        }

        $body = $this->payload($model, $state, $questions, $providerOptions);
        $configuration = $provider->additionalConfiguration();
        $key = $provider->providerCredentials()['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException("Parallel Decisions provider [{$provider->name()}] needs an API key.");
        }

        $url = is_string($configuration['url'] ?? null) ? $configuration['url'] : self::URL;
        $headers = [];
        foreach (is_array($configuration['headers'] ?? null) ? $configuration['headers'] : [] as $name => $value) {
            if (is_string($name)) {
                $headers[$name] = $value;
            }
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->createClient(
                rtrim($url, '/'),
                ['x-api-key' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $headers,
                $timeout,
            )->post(self::ENDPOINT, $body),
        );

        return $this->response($provider->name(), $response->json(), $questions);
    }

    /**
     * The exact request body for these questions.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<array-key, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     * @return array<string, mixed>
     *
     * @throws InvalidDecisionRequest when the request breaks a documented limit.
     */
    public function payload(string $model, string|array $state, array $questions, array $providerOptions = []): array
    {
        $mode = self::MODELS[$model] ?? throw new InvalidDecisionRequest("Parallel Decisions has no model [{$model}]. Use one of: ".implode(', ', array_keys(self::MODELS)).'.');

        $count = count($questions);
        if ($count < 1 || $count > self::MAX_QUESTIONS) {
            throw new InvalidDecisionRequest('Parallel Decisions accepts 1 to '.self::MAX_QUESTIONS." questions per request; {$count} given.");
        }

        $unknown = array_diff(array_keys($providerOptions), self::PROVIDER_OPTIONS);
        if ($unknown !== []) {
            throw new InvalidDecisionRequest('Parallel Decisions does not accept the provider options: '.implode(', ', $unknown).'.');
        }
        if (array_key_exists('include_excerpts', $providerOptions) && ! is_bool($providerOptions['include_excerpts'])) {
            throw new InvalidDecisionRequest('The include_excerpts provider option must be a boolean.');
        }

        $mapped = [];
        foreach ($questions as $key => $question) {
            $key = (string) $key;
            if (preg_match('/\A[A-Za-z0-9_-]+\z/', $key) !== 1) {
                throw new InvalidDecisionRequest("Question key [{$key}] may contain only ASCII letters, numbers, underscores and hyphens.");
            }
            $mapped[$key] = $this->question($key, $question);
        }

        // An object even when every key is numeric, such as "0" and "1", which PHP would encode as a list.
        return [...$providerOptions, 'mode' => $mode, 'state' => $state, 'questions' => (object) $mapped];
    }

    /**
     * @return array<string, mixed>
     */
    protected function question(string $key, Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $this->text($key, 'instructions', $question->instructions),
                'criteria' => $question->criteria === null ? null : $this->noulCriteria($key, $question->criteria),
            ], static fn (mixed $value): bool => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $this->text($key, 'instructions', $question->instructions),
                'criteria' => $this->bounded($key, 'options', $this->options($key, $question->options), self::MAX_CHOICE_OPTIONS),
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $this->text($key, 'instructions', $question->instructions),
                'criteria' => $this->bounded($key, 'levels', $this->texts($key, 'levels', $question->levels), self::MAX_SCORE_LEVELS),
            ],
            default => throw new InvalidDecisionRequest("Question [{$key}] is a ".$question::class.'; Parallel Decisions answers only Boolean, Choice and Score questions.'),
        };
    }

    protected function text(string $key, string $field, mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidDecisionRequest("Question [{$key}] {$field} must be non-empty text; Parallel Decisions accepts no structured or image content.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, string>
     */
    protected function texts(string $key, string $field, array $values): array
    {
        return array_map(fn (mixed $value): string => $this->text($key, $field, $value), $values);
    }

    /**
     * Parallel requires both the true and the false description when criteria are given.
     *
     * @param  array<array-key, mixed>  $criteria
     * @return array{true: string, false: string}
     */
    protected function noulCriteria(string $key, array $criteria): array
    {
        $given = array_map(strval(...), array_keys($criteria));
        sort($given, SORT_STRING);
        if ($given !== ['false', 'true']) {
            throw new InvalidDecisionRequest("Question [{$key}] criteria must describe both the true and the false case, or be omitted.");
        }

        return [
            'true' => $this->text($key, 'criteria', $criteria['true']),
            'false' => $this->text($key, 'criteria', $criteria['false']),
        ];
    }

    /**
     * Choice criteria. An option without a description, which laravel/ai
     * allows, is described by its own name, because Parallel requires a
     * description for every option.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, string>
     */
    protected function options(string $key, array $options): array
    {
        $criteria = [];
        foreach ($options as $name => $description) {
            $criteria[(string) $name] = $this->text($key, 'options', $description ?? (string) $name);
        }

        return $criteria;
    }

    /**
     * @param  array<array-key, string>  $values
     * @return array<array-key, string>
     */
    protected function bounded(string $key, string $field, array $values, int $maximum): array
    {
        if (count($values) > $maximum) {
            throw new InvalidDecisionRequest("Question [{$key}] has ".count($values)." {$field}; Parallel Decisions accepts at most {$maximum}.");
        }

        return $values;
    }

    /**
     * Validate and hydrate a raw Decisions response.
     *
     * @param  array<array-key, Question>  $questions
     *
     * @throws InvalidDecisionResponse when the response breaks the documented contract.
     */
    public function response(string $providerName, mixed $data, array $questions): DecisionResponse
    {
        $body = is_array($data) ? $data : [];
        $decisionId = is_string($body['decision_id'] ?? null) && $body['decision_id'] !== '' ? $body['decision_id'] : null;
        $usage = $this->usage($body['usage'] ?? null);
        $fail = static fn (string $rule, ?string $question = null): InvalidDecisionResponse => new InvalidDecisionResponse($rule, $question, $decisionId, $usage);

        if ($decisionId === null) {
            throw $fail('decision_id_missing');
        }
        if ($usage === null) {
            throw $fail('usage_invalid');
        }
        $model = is_string($body['mode'] ?? null) ? array_search($body['mode'], self::MODELS, true) : false;
        if (! is_string($model)) {
            throw $fail('mode_invalid');
        }
        if (! is_array($body['answers'] ?? null)) {
            throw $fail('answers_missing');
        }

        $raw = [];
        foreach ($body['answers'] as $key => $answer) {
            $raw[(string) $key] = $answer;
        }

        $answers = [];
        foreach ($questions as $key => $question) {
            $key = (string) $key;
            if (! array_key_exists($key, $raw)) {
                throw $fail('answer_missing', $key);
            }
            $answers[$key] = $this->answer($key, $question, $raw[$key], $fail);
            unset($raw[$key]);
        }
        if ($raw !== []) {
            throw $fail('answer_unexpected', (string) array_key_first($raw));
        }

        return new DecisionResponse(
            $answers,
            $usage,
            new Meta($providerName, $model, $this->citations($body['citations'] ?? null, $fail)),
            $decisionId,
        );
    }

    /**
     * @param  callable(string, ?string=): InvalidDecisionResponse  $fail
     */
    protected function answer(string $key, Question $question, mixed $answer, callable $fail): Answer
    {
        if (! is_array($answer)) {
            throw $fail('answer_invalid', $key);
        }

        $type = $answer['type'] ?? null;
        if ($type === 'refusal') {
            return new RefusalAnswer;
        }

        return match (true) {
            $question instanceof Boolean && $type === 'noul' => $this->noul($key, $answer, $fail),
            $question instanceof Choice && $type === 'choice' => $this->choice($key, $question, $answer, $fail),
            $question instanceof Score && $type === 'score' => $this->score($key, $question, $answer, $fail),
            default => throw $fail('answer_type_mismatch', $key),
        };
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @param  callable(string, ?string=): InvalidDecisionResponse  $fail
     */
    protected function noul(string $key, array $answer, callable $fail): BooleanAnswer
    {
        $probability = $this->probability($answer['noul'] ?? null);
        if ($probability === null) {
            throw $fail('noul_probability', $key);
        }

        return new BooleanAnswer($probability);
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @param  callable(string, ?string=): InvalidDecisionResponse  $fail
     */
    protected function choice(string $key, Choice $question, array $answer, callable $fail): ChoiceAnswer
    {
        $options = array_map(strval(...), array_keys($question->options));
        $probabilities = $this->distribution($key, $answer['probabilities'] ?? null, $options, $fail);
        $choice = $answer['choice'] ?? null;
        if (! is_string($choice) || ! in_array($choice, $options, true)) {
            throw $fail('choice_value', $key);
        }

        return new ChoiceAnswer($choice, $probabilities, $this->confidence($key, $answer, $fail));
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @param  callable(string, ?string=): InvalidDecisionResponse  $fail
     */
    protected function score(string $key, Score $question, array $answer, callable $fail): ScoreAnswer
    {
        $levels = count($question->levels);
        $indices = array_map(strval(...), range(0, $levels - 1));
        $probabilities = $this->distribution($key, $answer['probabilities'] ?? null, $indices, $fail);
        $score = $answer['score'] ?? null;
        if (! is_int($score) && ! is_float($score)) {
            throw $fail('score_value', $key);
        }
        $score = (float) $score;
        if (! is_finite($score) || $score < 0.0 || $score > $levels - 1) {
            throw $fail('score_value', $key);
        }

        $byLevel = [];
        foreach ($probabilities as $index => $probability) {
            $byLevel[(int) $index] = $probability;
        }
        ksort($byLevel);

        /** @var array<int, string> $legend */
        $legend = $question->levels;

        return new ScoreAnswer($score, $byLevel, $legend, $this->confidence($key, $answer, $fail));
    }

    /**
     * A complete distribution over exactly the expected keys.
     *
     * @param  list<string>  $keys
     * @param  callable(string, ?string=): InvalidDecisionResponse  $fail
     * @return array<string, float>
     */
    protected function distribution(string $question, mixed $raw, array $keys, callable $fail): array
    {
        if (! is_array($raw)) {
            throw $fail('probabilities_missing', $question);
        }

        $probabilities = [];
        foreach ($raw as $key => $value) {
            $probability = $this->probability($value);
            if ($probability === null) {
                throw $fail('probability_value', $question);
            }
            $probabilities[(string) $key] = $probability;
        }

        // PHP turns numeric string keys such as score levels into integers.
        $returned = array_map(strval(...), array_keys($probabilities));
        sort($returned, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($returned !== $keys) {
            throw $fail('probability_keys', $question);
        }
        if (abs(array_sum($probabilities) - 1.0) > self::PROBABILITY_TOLERANCE) {
            throw $fail('probability_sum', $question);
        }

        return $probabilities;
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @param  callable(string, ?string=): InvalidDecisionResponse  $fail
     */
    protected function confidence(string $key, array $answer, callable $fail): ?float
    {
        if (($answer['confidence'] ?? null) === null) {
            return null;
        }

        // Parallel documents confidence as a model-reported number without bounds.
        $confidence = $answer['confidence'];
        if ((! is_int($confidence) && ! is_float($confidence)) || ! is_finite((float) $confidence)) {
            throw $fail('confidence_value', $key);
        }

        return (float) $confidence;
    }

    protected function usage(mixed $usage): ?TextUsage
    {
        $input = is_array($usage) ? ($usage['input_tokens'] ?? null) : null;
        $output = is_array($usage) ? ($usage['output_tokens'] ?? null) : null;

        if (! is_int($input) || $input < 0 || ! is_int($output) || $output < 0) {
            return null;
        }

        return new TextUsage(inputTokens: $input, outputTokens: $output);
    }

    /**
     * @param  callable(string, ?string=): InvalidDecisionResponse  $fail
     * @return Collection<int, Citation>
     */
    protected function citations(mixed $raw, callable $fail): Collection
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw $fail('citations_invalid');
        }

        $citations = new Collection;
        foreach ($raw as $citation) {
            $url = is_array($citation) ? ($citation['url'] ?? null) : null;
            $title = is_array($citation) ? ($citation['title'] ?? null) : null;
            $excerpts = is_array($citation) ? ($citation['excerpts'] ?? []) : null;

            if (! is_string($url) || $url === '' || ($title !== null && ! is_string($title))
                || ! is_array($excerpts) || ! array_is_list($excerpts) || array_filter($excerpts, is_string(...)) !== $excerpts) {
                throw $fail('citations_invalid');
            }

            /** @var list<string> $excerpts */
            $citations->push(new DecisionCitation($url, $title, $excerpts));
        }

        return $citations;
    }

    /** The value as a probability when it is a finite JSON number in [0, 1], never a string or boolean. */
    protected function probability(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) && $value >= 0.0 && $value <= 1.0 ? $value : null;
    }
}
