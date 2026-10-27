# Compatibility: v2.1 to v3.0

Version 3.0 always uses the last server-verified visit before registration. There is no strategy flag. `tracking.last_valid_visit` and the first-touch/unverified capture branch have been removed; an old false setting no longer restores that behavior. Shadow still records local event snapshots but cannot verify or capture new visits. Off still stops new capture/recording, while consume-before-claim remains mandatory.

Require PHP SDK `^1.1`, the authenticated `POST /api/v1/tracking/clicks/resolve` endpoint and all five published migrations. Prepare the request outside the host transaction, then consume attribution, claim identity and freeze the customer outbox within the same connection transaction. The public facade and identity contract signatures and all historical migrations are unchanged. Internal `CapturePendingAttribution` now accepts `handle(Request $request, string $visitorId)`; the requestless overload and `handleRequest` entry were removed. `LinkadoConfiguration::lastValidVisit()` was removed.

Unverified pending rows, slug-only sources and malformed snapshots cannot establish attribution. Valid click cookies remain readable when the atomic snapshot is absent, with the same authenticated resolver verification. Renaming a participation slug does not invalidate its verified clicks. Do not backfill verification from browser values or change existing outbox bytes/history.

This is a breaking major release; published v2.1.0 and its opt-in behavior remain immutable. PHP SDK v1.1.0 already supports the required resolver and needs no release for this cleanup. Run installed consumer acceptance after publication; source tests are not proof of Packagist installation. Disable the affected integration before rollback and preserve schema/history.

The v1.0→v1.1 comparison below is historical.

## Historical v1.0.0 to v1.1.0 changes

Baseline: published `v1.0.0`, source `a3fa7fcb82d5e4af5a770024bb2f547441029b4a`. The following sections retain the historical v1.1 changes; the current contract and release gates are above.

## Supported integration API

| Surface | Difference from v1.0.0 |
| --- | --- |
| `Linkado::record(string $sourceKey, Closure $eventFactory): ?OutboxEvent` | Signature and official SDK event payload semantics unchanged |
| `Linkado::attribution(): AttributionManager` and `consume(Request $request): ?ConsumedAttribution` | Signatures unchanged; stricter source/identity matching and transactional consume semantics below |
| `DeterminesLinkadoEligibility::allows(LinkadoFeature $feature, EligibilityContext $context): bool` | Unchanged; now consistently gates capture, visitor issuance and rendering |
| `ResolvesLinkadoSsoUser::resolve(Authenticatable $user): ResolvedSsoUser` | Unchanged |
| Existing value objects and enum values | Unchanged |
| `LocksLinkadoAttributionIdentity::withLockedIdentity(Request $request, Closure $operation): void` | New optional contract; callback `Closure(AttributionIdentity): void` |
| `AttributionIdentity::__construct(string $key, bool $captureAllowed)` | New final readonly value object; promoted public fields |
| Default bindings | Existing defaults retained; no default identity adapter binding |
| Config keys/defaults, PHP/SDK/Laravel constraints | Unchanged; PHP ^8.3, Laravel ^13.0, SDK ^1.0 |
| SSO POST route/name/middleware, attribution alias, Blade directive, publish tags, commands | Unchanged |

Implementation classes are container-resolved, not host extension points. For consumers that directly instantiate them, the constructor diff is explicit: capture/consume actions gain `IdentityLock`; capture middleware and visitor middleware gain `TrackingGate`; renderer replaces its eligibility dependency with `TrackingGate` and removes the former fifth `Request $request` constructor argument, resolving the current request per render instead. The capture action adds request-aware `handleRequest(Request $request, string $visitorId, mixed $clickId, mixed $referralSlug): void`; existing `handle()` remains and refuses writes when an identity adapter is bound. Use middleware and the facade instead of constructing these internals. `CreateSsoLink::handle()` keeps its signature with added `SensitiveParameter` attributes.

## Behavior and schema

- SSO validates exact HTTPS host/effective port, rejects user-info and unsafe URL characters, redacts failures, and returns the one-time URL only in successful `Location`.
- Hosted markup adds `data-endpoint`, `data-program-key`, `data-referral-param`, `data-attribution-window-days`, retaining equal-valued old endpoint/referral aliases and `defer`. Hosted TTL must be positive whole days; cookie names must be `lk_click` and `lk_referral`. Server TTL remains in seconds.
- First active attribution is immutable except slug → click upgrade with the original timestamps. At `expires_at <= now`, a new eligible touch may open a new window. Consume/discard scrubs sources and retains a marker until expiry; rollback restores the original row. Prune removes expired rows/markers.
- Capture and consume validate ULIDs and lowercase ASCII single-hyphen slugs of 1–100 characters without normalization. Existing referral cookie wins over query; valid click has priority; malformed selected input cannot fall back. Consume requires exact source cookies, never query confirmation. Mismatch discards only an owned visitor row; identity mismatch precedes discard and leaves the other identity's row untouched. Cookie matching is not remote click authentication.
- Eligibility is read-only and receives current request/user context. Policy/config failure disables optional tracking, while downstream/DB errors propagate. No policy result is cached between operations.
- An optional identity adapter locks and re-reads the host row before the pending row, on the same connection and transaction. Registration consumes before claim even without cookies/pending attribution or enabled customer events. Unresolved/closed identity never falls back. Legacy unbound rows are not adopted; bound rows remain inaccessible after adapter removal. A fresh lifecycle check still denies closed identities after expiry/prune/visitor rotation.
- Exactly one new migration, `2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php`, adds nullable `varchar(64) identity_hash`. The three published create migrations are unchanged. No host FK, lifecycle registry, source backfill, SDK DTO, host dependency or config key is added.
- Outbox persistence, exact payload/hash, idempotency, after-commit dispatch, retry/claim recovery and rollback remain unchanged. Registration never performs a Linkado HTTP call.

## Cutover and rollback

1. Pause affected attribution capture and registration/claim transitions before deploying new package code.
2. Publish `linkado-migrations` and apply the additive migration on the configured package connection, including integrations without an adapter. Preserve published configuration and existing migration filenames.
3. Verify `linkado:health --json` in shadow mode with features disabled. Off mode does not inspect schema. Check `linkado:diagnose --json`, caches and application integration tests.
4. If opting in, bind the generic adapter shown in the README and prove every claim path follows same-connection consume-before-claim. Do not guess associations for legacy rows. Then resume traffic/features.
5. For rollback, first disable affected integration. Do not automatically drop the migration or promise that older capture/consume restores lifecycle guarantees.

The historical v1.1 release category was **minor**, based on preserved supported API and the additive optional identity extension. The deliberate tightening of unsafe SSO and attribution behavior required migration/integration testing; unchanged PHP signatures did not mean unchanged acceptance of insecure inputs. V2 is major, with its own gates above.

## Evidence boundaries

Required CI: Ubuntu PHP 8.3/8.4/8.5 × prefer-lowest/prefer-stable, Laravel 13; MySQL 8.4, MariaDB 11.4 and PostgreSQL 17 with real process concurrency. CI runs on Ubuntu only; Windows is not part of the matrix. Local macOS results do not prove those Ubuntu jobs. Test fixtures are excluded from distribution; no tracking JavaScript is bundled as a production asset.

Before publication, artifact repository installs can prove discovery and upgrade behavior. They cannot prove delivery through Packagist. Release requires separately approved publication, verified remote tag/source reference, exact published-version clean Laravel 13 installation and dependency resolution in a disposable host copy without path/artifact overrides. The consuming application must independently prove SSO, hosted loading/CSP/cookies, identity resolution, middleware ordering and registration locking.
