# Contributing

Thanks for considering a contribution to Laravel AI Parallel Decisions.

## Before opening an issue

- Search existing issues and discussions.
- Use Discussions for questions and early feature ideas.
- Use the bug template for reproducible defects.
- Report security issues privately according to [SECURITY.md](SECURITY.md).

## Development setup

```bash
git clone https://github.com/jkudish/laravel-ai-parallel-decisions.git
cd laravel-ai-parallel-decisions
composer install
```

Run one test file or test while you work:

```bash
vendor/bin/pest tests/Feature/ParallelDecisionsTest.php --filter="refusals"
```

Run the complete local verification suite:

```bash
composer test
composer analyse
composer lint:check
composer validate --strict
composer audit
```

## Changing the wire format

Parallel's [API reference](https://docs.parallel.ai/api-reference/decisions/make-web-grounded-typed-decisions) is the source of truth for request and response shapes and limits. When it changes:

- [ ] Link the reference section in the pull request.
- [ ] Update the limits in `ParallelDecisionsGateway` and the README's validation section together.
- [ ] Add a test with an input where the old and new behavior differ.
- [ ] Keep every test offline. Fake HTTP; never send a live request from the suite.

## Pull requests

- Keep changes focused and include tests for behavioral changes.
- Keep refusals distinct from answers, and keep invalid responses failing loudly.
- Update the README or changelog when the public API or supported behavior changes.
- Ensure all automated checks pass before requesting review.

By participating, you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
