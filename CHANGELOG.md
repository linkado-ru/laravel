# Changelog

All notable changes to `linkado-ru/laravel` are documented here. The package follows [Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

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
