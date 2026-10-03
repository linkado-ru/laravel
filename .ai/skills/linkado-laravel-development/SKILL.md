---
name: linkado-laravel-development
description: Develop or review the linkado-ru/laravel package itself, including configuration, public contracts, attribution, outbox delivery, tracking, SSO, migrations, tests, and package documentation. Application integration belongs to the bundled Boost skill.
---

# Linkado Laravel Development

## Establish the contract

Read Git status and the current diff before editing; preserve unrelated and existing candidate changes. Use public code and its tests, [README](../../../README.md), [compatibility](../../../docs/compatibility.md), and [contribution rules](../../../.github/CONTRIBUTING.md) as the source of truth. Do not infer API fields or endpoints when runtime code is available.

The package owns outbox persistence, delivery, retries, recovery, tracking and SSO infrastructure. Consumers own business producers, adapters, eligibility and their transaction boundaries. The PHP SDK owns DTOs and the transport contract; the v2 Laravel candidate requires no SDK release. Keep host domain models, Hey AI cohort/enrollment/impersonation policy, and Horizon dependencies out of the package.

For application work, read the [package-facing Boost skill](../../../resources/boost/skills/linkado-development/SKILL.md). For version preparation or publication, use `$linkado-laravel-release` and [RELEASING.md](../../../RELEASING.md).

## Preserve v2 behavior

- Defaults: `live`; tracking/customer/billing/refund enabled; SSO disabled; `via`; `5184000` seconds (60 days).
- `LINKADO_URL` is an HTTPS origin. `LINKADO_BASE_URL` retains full API URL semantics, including custom prefixes. Origin wins; validate both even when legacy loses precedence. Compute API/script/click endpoints locally. Invalid nonempty settings never fall back to production; tracking CDN/proxy overrides do not change API or SSO authority.
- `Linkado::enabled(LinkadoFeature): bool` checks package mode, feature and configuration without host eligibility, DB or HTTP. Package readiness precedes host policy and lazy SSO adapters. Missing live token/program stops new integration operations without constructing the connector or sending HTTP.
- Shadow requires a program key, captures locally and records terminal snapshots, but renders no hosted script and sends no outbox or SSO HTTP. Off stops new capture/recording; it does not remove the consume-before-claim obligation.
- Missing live configuration leaves pending delivery unclaimed, with its snapshot and attempt count intact for recovery. Mode changes and retry/recovery retain their existing tested behavior.

## Preserve transactional compatibility

- Build official SDK DTOs with the factory-provided event ID. Preserve source keys, exact payload bytes and SHA-256 hash across duplicates, retries and recovery; never reconstruct an event from current host state for delivery.
- Host facts, attribution consumption and outbox recording use the configured package connection and an active host transaction. Dispatch occurs only after the outer commit; rollback restores consumption and suppresses dispatch.
- Registration consumes before claiming identity even without cookies/pending attribution, in off mode or with customer recording disabled. The optional adapter freshly locks the host identity before the pending row, on the same connection; unresolved/closed identities never fall back to visitor-only behavior.
- Preserve public facade/contract/value-object signatures and all four migration filenames, definitions and existing history. Do not add a migration merely for the v2 defaults change or backfill guessed identity associations.
- Keep the two explicitly approved `@phpstan-ignore return.unusedType` annotations on nullable tracking URL getters. Do not widen suppressions or change those public nullable signatures.
- Diagnostics exclude credentials, raw cookies, DTO payloads, PII and one-time SSO URLs. Consumer observability remains consumer-owned.

## Verify the affected behavior

Use [configuration tests](../../../tests/Feature/ConfigurationResolutionTest.php) and the corresponding [public-contract](../../../tests/Feature/Documentation/PublicApiContractTest.php), [signature](../../../tests/Unit/ContractSignaturesTest.php), [identity](../../../tests/Feature/Attribution/IdentityAttributionTest.php), [after-commit](../../../tests/Feature/Outbox/AfterCommitDispatchTest.php), [delivery](../../../tests/Feature/Delivery), [tracking](../../../tests/Feature/Tracking), [SSO](../../../tests/Feature/Sso), and [schema](../../../tests/Feature/Database/SchemaTest.php) coverage. Add a focused behavioral regression when changing runtime behavior; do not add tests that only match skill wording.

Ordinary tests force off/blank URL and credentials/false feature flags through both PHPUnit environment readers, use a per-application `APP_CONFIG_CACHE`, and explicitly opt into synthetic live settings and Saloon fakes with stray-request prevention. Never use a real `.env`, production cache or external endpoint to make ordinary tests pass. Hosted acceptance is separate and opt-in; browser processes receive public settings, never a real token.

Ensure Node 24 is on PATH for the hosted fixture contracts. Run focused tests while iterating, then `composer test` and `git diff --check`. `composer test` includes PHPStan, Pint check, Rector dry-run, type coverage and parallel Pest. After PHP edits, use the existing `vendor/bin/pint --dirty --format agent`, then recheck. Use `composer validate --strict` for metadata changes. Update README, CHANGELOG, compatibility and the Boost skill for public behavior changes.

The [CI workflow](../../../.github/workflows/tests.yml) defines Ubuntu PHP 8.3/8.4/8.5 × lowest/stable dependencies and MySQL 8.4/MariaDB 11.4/PostgreSQL 17, including real process concurrency. Server DB tests require disposable databases; local SQLite/macOS checks do not prove remote CI or hosted acceptance.
