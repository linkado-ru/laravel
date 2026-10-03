# Linkado Laravel Agent Instructions

This is the official `linkado-ru/laravel` Composer package: PHP 8.3+, Laravel 13, official PHP SDK DTOs and Laravel-native infrastructure.

## Local skills

- Use [linkado-laravel-development](.ai/skills/linkado-laravel-development/SKILL.md) when developing or reviewing the package, its tests or public documentation.
- Use [linkado-laravel-release](.ai/skills/linkado-laravel-release/SKILL.md) for SemVer, release preparation, authorized publication and post-release verification. Read [RELEASING.md](RELEASING.md).
- `.ai/skills` is canonical; `.agents/skills` contains relative discovery symlinks. Keep [the Boost skill](resources/boost/skills/linkado-development/SKILL.md) focused on consuming Laravel applications.

## Package rules

- Read current status/diff first and preserve existing candidate work. The Laravel v2 defaults change is major; SDK v1.0.0 needs no separate release.
- The package owns outbox/delivery/retries/recovery/tracking/SSO infrastructure. Consumers own business producers, adapters, eligibility and transaction boundaries; the SDK owns DTOs and transport. Keep application-specific cohort/enrollment/impersonation policy out of package guidance.
- Preserve event IDs, source keys and exact payload/hash; use the same configured connection for host and package transactions, with dispatch after outer commit. Consume before identity claim even when recording is disabled.
- Preserve the four migrations and existing history, public signatures, and the two approved nullable tracking getter `return.unusedType` suppressions without widening them.
- Keep credentials, one-time SSO URLs, raw cookies and prohibited PII out of diagnostics. Isolate ordinary tests from real `.env`, shared config cache and external HTTP.
- Follow the contribution guide's English technical text and English/Russian user-facing translations.

## Verification

Ensure Node 24 is on PATH for hosted fixture tests. Run focused behavioral tests, then `composer test` and `git diff --check`. Run `composer validate --strict` for package metadata. `composer lint:check` is Pint's non-mutating check; after PHP edits use `vendor/bin/pint --dirty --format agent` and recheck. Local results do not prove the full Ubuntu CI matrix, Packagist installation or hosted/application acceptance.

## Current documentation

Use Context7 MCP for current library, framework, SDK, API, CLI or cloud-service documentation, including syntax, configuration, migrations, setup and library-specific debugging. Start with `resolve-library-id` using the library name and question unless an exact library ID is provided; select the best relevant reputable match, then `query-docs` scoped to one concept per call. Prefer version-specific IDs when available. Do not require Context7 for refactoring, scripts from scratch, business-logic debugging, code review or general programming concepts.
