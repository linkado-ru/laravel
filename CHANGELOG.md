# Changelog

All notable changes to `linkado-ru/laravel` are documented here. The package follows [Semantic Versioning](https://semver.org/).

## 2.0.0 - 2026-10-03

### Breaking defaults

- Default to live with tracking/customer/billing/refund enabled, SSO disabled, `via` and 60-day attribution. Two credentials enable a standard production program after host setup; missing credentials fail closed without breaking application boot.
- Add HTTPS origin `LINKADO_URL` and locally computed API/script/click URLs. Preserve full-API semantics of legacy `LINKADO_BASE_URL`; validate ignored/advanced settings and never fall back to production on invalid input.
- Expose `Linkado::enabled()` and share readiness across capture, rendering, recording and SSO. Shadow keeps only local capture/recording; SSO adapters resolve lazily after readiness/policy.
- Keep pending delivery unclaimed while live credentials are unavailable. Add redacted configuration diagnostics and missing-schema reporting; installation remains publish-only.

V2 requires explicit migration; SDK v1.0.0 and the four published migrations remain unchanged.

### Upgrade

- Preserve prior mode and feature flags explicitly before changing the constraint to `^2.0`. Manually merge published configuration without `--force`; retain full API semantics for `LINKADO_BASE_URL`, or use an HTTPS origin with `LINKADO_URL`.
- Verify all four migrations, including the identity migration for v1.0 consumers. V1.1-to-v2 adds no migration. Complete consumer acceptance before rebuilding config cache and restarting workers through its deployment procedure.

### Added

- Repository development/release AI skills with relative discovery aliases, a staged release procedure, and updated consumer-facing Boost guidance for v2 installation, upgrades and operations. Local maintainer instructions are excluded from dist; the Boost skill remains bundled.

## 1.1.0 - 2026-09-21

### Added

- Optional host identity locking through `LocksLinkadoAttributionIdentity` and `AttributionIdentity`, with a new nullable `identity_hash` migration. Capture and consumption serialize with host registration on the same configured connection, preserving active ownership and rejecting closed or unresolved identities without visitor-only fallback. Call consume before host claim, including when attribution or customer events are absent. Legacy unbound rows are not backfilled; publish/apply the migration before enabling an adapter.

### Security

- Reject SSO redirects with user-info, unsafe characters or an unexpected HTTPS authority/port; redact SDK/resolver failures and expose successful one-time URLs only in `Location`.

### Fixed

- Match the actual hosted script's endpoint, program key, referral parameter and whole-day TTL attributes while preserving old markup aliases. Incompatible TTL/cookie names or missing configuration render nothing; no API token is exposed.

- Validate captured and stored attribution identifiers, prefer an existing referral cookie over query, and require exact source-cookie matching during consumption. Missing, malformed, or mismatched sources return no attribution and leave a scrubbed marker until the original expiry without emitting a consumption event. Public signatures, event serialization, and schema are unchanged.

- Apply host tracking eligibility consistently to capture, hosted markup, and new visitor cookies using the current request and user. Resolve policies lazily and fail closed on policy/configuration errors without swallowing downstream or database failures.

- Preserve the first active attribution and its absolute expiry, allowing only referral-to-click upgrades within the original window.
- Serialize attribution capture and consumption, handle concurrent inserts through savepoints, and retain scrubbed consumed markers until expiry to prevent recapture. Existing public signatures and schema are unchanged.

### Upgrade

- Publish/apply the additive identity migration before upgraded capture/consume, including visitor-only integrations. Pause affected capture/registration for schema cutover, verify health in shadow mode, then enable the optional adapter and resume. Do not backfill legacy identities or drop the column automatically on rollback.
- The v1.1 release category was minor: existing supported signatures/configuration/SDK constraints remained, with opt-in identity API and stricter security behavior. See [compatibility and migration notes](docs/compatibility.md). See [RELEASING.md](RELEASING.md) for current publication gates.
- Expand database regression CI to all database, attribution, outbox and delivery tests plus mandatory process concurrency. Run CI on Ubuntu only, with all PHP/dependency and server database cells required.

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
