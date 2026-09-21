# Changelog

All notable changes to `linkado-ru/laravel` are documented here. The package follows [Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- Optional host identity locking through `LocksLinkadoAttributionIdentity` and `AttributionIdentity`, with a new nullable `identity_hash` migration. Capture and consumption serialize with host registration on the same configured connection, preserving active ownership and rejecting closed or unresolved identities without visitor-only fallback. Call consume before host claim, including when attribution or customer events are absent. Legacy unbound rows are not backfilled; publish/apply the migration before enabling an adapter.

### Fixed

- Validate captured and stored attribution identifiers, prefer an existing referral cookie over query, and require exact source-cookie matching during consumption. Missing, malformed, or mismatched sources return no attribution and leave a scrubbed marker until the original expiry without emitting a consumption event. Public signatures, event serialization, and schema are unchanged.

- Apply host tracking eligibility consistently to capture, hosted markup, and new visitor cookies using the current request and user. Resolve policies lazily and fail closed on policy/configuration errors without swallowing downstream or database failures.

- Preserve the first active attribution and its absolute expiry, allowing only referral-to-click upgrades within the original window.
- Serialize attribution capture and consumption, handle concurrent inserts through savepoints, and retain scrubbed consumed markers until expiry to prevent recapture. Existing public signatures and schema are unchanged.

## 1.0.0 - 2026-09-21

### Added

- Laravel 13 package discovery, install command, publish tags, translations, and portable migrations.
- Strict Linkado configuration with `off`, `shadow`, and `live` delivery modes plus per-feature gates.
- Transactional idempotent recording with immutable official SDK payloads and after-commit queue dispatch.
- Hosted tracking, protected visitor cookies, pending attribution capture, transactional consumption, and pruning.
- Authenticated, throttled SSO launch flow with application-owned eligibility and user-resolution contracts.
- Atomic outbox claims, bounded retry policy, stale recovery, audited manual retry, health reporting, and diagnostics.
- SQLite, MySQL, MariaDB, PostgreSQL, Windows, and supported PHP-version CI coverage.

### Security

- Credentials, one-time SSO URLs, raw visitor identifiers, and event payloads are excluded from package diagnostics.
- Payload hashes, claim tokens, source-key conflict detection, and safe failure classification protect delivery integrity.
