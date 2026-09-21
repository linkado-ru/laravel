---
name: linkado-development
description: >
  Use when integrating or upgrading linkado-ru/laravel tracking, attribution,
  anonymous identity registration, SSO or transactional delivery in Laravel 13.
license: MIT
metadata:
  author: everully
---

# Linkado Laravel

Use this skill when a Laravel application installs, configures, tests, or operates `linkado-ru/laravel`.

## Primary goal

Adopt the package through its configuration, contracts, facade, middleware, Blade directive, named route, and Artisan commands without depending on package internals.

## Workflow

1. Confirm PHP 8.3+ and Laravel 13, then run `composer require linkado-ru/laravel`, `php artisan linkado:install`, review the published files, and run migrations.
2. Start with `LINKADO_MODE=shadow`. Configure the Linkado token, base URL, program key, database connection, queue, and only the required feature flags.
3. Add `linkado.attribution` to public landing routes and `@linkadoTracking` once in the page layout when tracking is enabled.
4. Inside a transaction on `config('linkado.connection')`, call `Linkado::attribution()->consume($request)` and `Linkado::record($sourceKey, $factory)`. The factory must use its provided event ID and return an official `linkado-ru/php-sdk` event DTO.
5. Bind `DeterminesLinkadoEligibility` for application policy and bind `ResolvesLinkadoSsoUser` before enabling SSO. Launch SSO only with a CSRF-protected POST to `route('linkado.sso.launch')`.
6. Run a queue worker and Laravel's scheduler. Check `linkado:health` and `linkado:diagnose` before moving to `LINKADO_MODE=live`.

## Tracking contract

`@linkadoTracking` emits `defer`, `data-endpoint`, `data-program-key`, `data-referral-param`, and `data-attribution-window-days`; old `data-endpoint-url` / `data-referral-parameter` remain equal-valued aliases. Only the public program key is rendered, never the token. Hosted rendering needs positive whole-day `ttl_seconds` (exact multiple of 86400), fixed `lk_click` / `lk_referral` names and valid URLs/configuration. A 120-second TTL or custom source-cookie name renders nothing. Server storage retains second-level TTL.

The first active source fixes `captured_at` and `expires_at`; only slug → click may change source, without extending that window. Valid click wins; an existing referral cookie wins over query. ULIDs and 1–100 character lowercase ASCII slugs with single hyphens are validated without normalization or malformed-input fallback. Consume requires exact matching source cookies, never query confirmation. Missing/mismatched source returns null and scrubs an owned row into a marker until original expiry. Identity mismatch leaves the row untouched. Matching does not authenticate a click remotely.

`DeterminesLinkadoEligibility` gates markup, new visitor cookies and capture with current request/user context. Keep it read-only. Mode off/disabled feature stops before policy resolution; policy/config failures fail closed, while downstream/DB failures propagate. Put host context middleware before capture. Eligibility alone does not serialize registration.

## Anonymous identity registration

Bind optional `LocksLinkadoAttributionIdentity::withLockedIdentity(Request $request, Closure $operation): void`. The contract namespace is `Linkado\Laravel\Contracts`; its callback is `Closure(AttributionIdentity): void`. `Linkado\Laravel\Support\Attribution\AttributionIdentity` is final readonly with `__construct(string $key, bool $captureAllowed)` and matching public readonly fields. Use a stable opaque trusted server-side key, not PII or untrusted cookie/query input. Only a namespaced hash is persisted.

The adapter runs inside an active transaction on `linkado.connection`: resolve identity, freshly read its host row under `lockForUpdate()`, then invoke the callback synchronously once while locked (zero times if unresolved). `captureAllowed` reflects fresh lifecycle state. Do not commit/roll back that transaction or call HTTP. The README contains a generic adapter example; the host owns the table and lifecycle.

Registration order is mandatory: begin the host transaction on that same connection → `Linkado::attribution()->consume($request)` → claim identity/create business record → optionally `Linkado::record()` → commit. Consume even without cookies or pending attribution and when customer events are disabled. Every competing claim path follows this protocol. Lock order is host identity → pending row; hold the identity lock until outer commit. Rollback restores claim/consume and suppresses dispatch. Successful claim rejects late captures after expiry/prune/visitor rotation.

Without an adapter, visitor-only mode remains. With one, unresolved/closed identities never fall back; requestless capture cannot bypass it. Do not attach legacy unbound rows to an identity or expose bound rows by removing the adapter.

## Upgrade and rollback

The candidate since v1.0.0 is minor: supported signatures/configuration remain, with optional identity API and stricter security behavior. Read `docs/compatibility.md` for the explicit diff. Before deploying, pause affected capture/registration, publish/apply `2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php`, then verify health in shadow mode with features disabled. The nullable column is required even without an adapter; the three old migrations remain unchanged. Off-mode health does not check schema. Enable the adapter only after migration and integration tests, then resume. Never guess/backfill legacy identities. Disable integration before rollback; do not automatically drop the column or claim old code preserves lifecycle safety.

Verify the actual hosted asset and the application's CSP/cookie behavior. Local fixture parity alone does not prove production integration. SSO accepts exact configured HTTPS host/effective port, rejects user-info/unsafe authorities, sanitizes failures and puts the one-time URL only in successful Location.

## Examples

```php
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Facades\Linkado;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;

DB::connection(config('linkado.connection'))->transaction(function () use ($customer): void {
    Linkado::record(
        sourceKey: 'customer-created:'.$customer->getKey(),
        eventFactory: fn (string $eventId): CustomerCreatedEventData => new CustomerCreatedEventData(
            event_id: $eventId,
            program_key: (string) config('linkado.program_key'),
            occurred_at: now(),
            external_customer_id: (string) $customer->getKey(),
        ),
    );
});
```

Use a deterministic source key derived from the host business event. Keep PII and secrets out of source keys and SDK metadata.

## Public operations

- Publish tags: `linkado`, `linkado-config`, `linkado-migrations`, `linkado-lang`.
- Commands: `linkado:install`, `linkado:health`, `linkado:diagnose`, `linkado:recover`, `linkado:retry`, and `linkado:prune`.
- Automatic schedules: recovery every five minutes and attribution pruning daily, both overlap-protected.
- `linkado:retry` requires an event ID plus non-empty `--operator` and `--reason`; use it only after diagnosis.

## References

- `config/linkado.php` for the complete configuration surface
- `README.md` for setup, contracts, event families, SSO, operations, privacy, and upgrades
- official `linkado-ru/php-sdk` DTOs and enums for event construction

## Anti-patterns

- Do not send HTTP requests from host business transactions; use `Linkado::record()`.
- Do not call `record()` or attribution consumption without an active transaction on the configured connection.
- Do not generate a replacement event ID or recreate SDK DTOs in application/package namespaces.
- Do not use GET for SSO or log its one-time redirect URL.
- Do not enable `live` without a queue worker, scheduler, migrations, health checks, and verified shadow-mode behavior.
- Do not add Horizon, host user/billing model, or observability dependencies to the package.
