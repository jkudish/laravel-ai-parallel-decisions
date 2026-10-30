# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `parallel` driver for the Laravel AI SDK's `Classification::of()`, backed by `POST https://api.parallel.ai/v1/decisions`.
- Boolean, Choice and Score questions map to Parallel's `noul`, `choice` and `score` types. A Choice option without a description is described by its name.
- `DecisionResponse` with `decisionId`, `citations()` and `refusals()`. Citations are `DecisionCitation` objects with `excerpts`, stored in `meta->citations`.
- `RefusalAnswer` for questions Parallel declines to answer.
- `InvalidDecisionRequest` for requests that break a documented limit, refused before sending, including Boolean criteria that describe only one case.
- `InvalidDecisionResponse` for responses that do not match the questions asked, carrying the decision ID and usage when reported.
- Rate limits, exhausted credits and outages raise the SDK's failover exceptions.

[Unreleased]: https://github.com/jkudish/laravel-ai-parallel-decisions/commits/main
