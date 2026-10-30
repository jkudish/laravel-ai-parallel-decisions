# Security Policy

## Supported versions

Security fixes are provided for the latest tagged release. Before `1.0.0`, fixes may require upgrading to the newest minor release because the public API is still stabilizing.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability.

Report vulnerabilities through [GitHub private vulnerability reporting](https://github.com/jkudish/laravel-ai-parallel-decisions/security/advisories/new) or email [joey@jkudish.com](mailto:joey@jkudish.com). Include the affected version, impact, reproduction steps, and any suggested mitigation.

You can expect an acknowledgement within five business days. Confirmed issues will be coordinated privately until a fix and disclosure plan are ready.

## Scope

This package sends your classification state and questions to the Parallel Decisions API with your API key. Reports involving credential leakage, requests sent to an unexpected host, or malformed responses accepted as valid answers are especially welcome.
