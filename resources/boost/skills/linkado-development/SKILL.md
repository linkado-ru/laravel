---
name: linkado-development
description: >
  Install, integrate, upgrade, test or operate linkado-ru/laravel in Laravel 13
  applications, including tracking, attribution, host identity adapters, SSO and
  transactional event recording. Package maintenance and publication are separate.
license: MIT
metadata:
  author: everully
---

# Linkado Laravel Application Integration

Use public configuration, contracts, facade, middleware, Blade directive, named route and Artisan commands. The package owns outbox, delivery, retries, recovery, tracking and SSO infrastructure; the application owns business producers, adapters, eligibility and transaction boundaries. The PHP SDK owns DTOs and transport. Do not build a second outbox/retry loop or introduce application-specific cohort/enrollment/impersonation policy into package instructions.

## Locate the installed contract

Read the installed package's README, CHANGELOG, `docs/compatibility.md` and `config/linkado.php`. Paths below are relative to the **application root**, not this copied skill: `vendor/linkado-ru/laravel/README.md`, `vendor/linkado-ru/laravel/docs/compatibility.md`, and `vendor/linkado-ru/laravel/config/linkado.php`. With a custom vendor directory, obtain the package root from `Composer\InstalledVersions::getInstallPath('linkado-ru/laravel')` after loading the application's Composer autoloader. Resolve the documents under that root; do not mistake application files for package documentation.

## Install and configure

1. Confirm PHP 8.3+ and Laravel 13. Install with `composer require linkado-ru/laravel`, run `php artisan linkado:install`, review published files and run the application's migration process. Install is idempotent and publish-only; it does not migrate. Publish tags are `linkado`, `linkado-config`, `linkado-migrations`, `linkado-lang`.
2. V2 defaults to `live`, tracking/customer/billing/refund enabled, SSO disabled, `via` and `5184000` seconds (60 days). Choose an explicit mode/flags while integrating; adding credentials to the default live configuration enables operations immediately. For local-only validation use `LINKADO_MODE=shadow` with a public program key; no token is required and no hosted script, SSO or outbox HTTP runs.
3. A standard production program needs `LINKADO_TOKEN` and `LINKADO_PROGRAM_KEY` after migrations, host producers/adapters, workers and scheduler are ready. Missing either live credential stops new capture/markup/recording/SSO without HTTP and preserves normal boot. Pending delivery remains unclaimed, without new attempts, for recovery after configuration repair.
4. `LINKADO_URL` is a raw HTTPS origin, for example `https://linkado.test`; endpoints are computed locally as `/api/v1`, `/build/tracking.js`, and API base plus `/tracking/clicks`. Legacy `LINKADO_BASE_URL` remains a full API URL with any custom prefix. Origin wins, but both are validated. Blank optional settings use defaults; malformed nonempty settings never fall back to production. CDN/proxy overrides affect tracking only, not API or SSO authority.
5. Call `Linkado::enabled(LinkadoFeature $feature)` before host policy/UI. It checks mode/flag/configuration without host eligibility, DB or HTTP. Tracking eligibility remains read-only and uses current request/user context; resolve required host middleware context before visitor issuance/capture. Policy/config failures fail closed; downstream application/DB failures still propagate.

## Record business events and consume attribution

Use official `linkado-ru/php-sdk` event DTOs. Use a deterministic source key for the host business event and the factory-provided event ID. Derive `occurred_at` from the original business fact, such as the customer's creation timestamp, rather than the current time on each invocation. Keep attribution and other payload fields stable when recording the same source again; consumption returns attribution only once. Preserve exact event/payload/hash across retries; keep PII/secrets out of source keys and SDK metadata. Amounts are positive integers in minor currency units. Do not perform HTTP inside a host business transaction.

Use an active transaction on `config('linkado.connection')` for the business write, consumption and recording. Delivery dispatches after the outer commit; rollback restores consumption and suppresses dispatch. For a registration/identity claim, the order is transaction → consume → claim/create business record → optional record → commit. Consume even without cookies/pending attribution, in off mode, or with customer recording disabled; do not gate consumption on `Linkado::enabled()`.

A recording example for an already resolved customer (registration must also follow the consume-before-claim order):

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
            occurred_at: $customer->created_at,
            external_customer_id: (string) $customer->getKey(),
        ),
    );
});
```

For anonymous identity lifecycles, optionally bind `Linkado\Laravel\Contracts\LocksLinkadoAttributionIdentity::withLockedIdentity(Request $request, Closure $operation): void`. Its callback receives `Linkado\Laravel\Support\Attribution\AttributionIdentity(string $key, bool $captureAllowed)`. The adapter re-reads a trusted opaque host identity under `lockForUpdate()` on the same connection inside the caller transaction, invokes the callback synchronously once while locked (zero times if unresolved), and does not commit/roll back or call HTTP. Only a namespaced identity hash is persisted. Use the generic README adapter example; the host owns the table/lifecycle.

All competing claim paths lock host identity before pending attribution and hold the lock through outer commit. Unresolved/closed identities never fall back to visitor-only mode; requestless capture cannot bypass a bound adapter. Do not adopt legacy unbound rows or expose bound rows by removing the adapter. Identity mismatch leaves the row untouched; successful claim prevents late captures after expiry/prune/visitor rotation.

## Tracking and SSO

Apply `linkado.attribution` to intended public landing routes and render `@linkadoTracking` once in the layout. Hosted rendering requires live readiness, eligibility, positive whole-day TTL and fixed `lk_click`/`lk_referral` source-cookie names. Server capture still supports second-level TTL. Markup keeps `defer`, `data-endpoint`, `data-program-key`, `data-referral-param`, `data-attribution-window-days`, and equal-valued old aliases `data-endpoint-url`/`data-referral-parameter`; never render the API token.

The first active touch fixes capture/expiry; only slug → click may upgrade within that original window. Valid click wins; referral cookie wins over query. Validate ULIDs/lowercase ASCII slugs without normalization or malformed-input fallback. Consume requires exact matching source cookies, never query confirmation; mismatch scrubs an owned row into a marker until original expiry. Cookie consistency does not remotely authenticate a click.

Bind `DeterminesLinkadoEligibility` for application policy; bind `ResolvesLinkadoSsoUser` before explicitly enabling SSO. Launch only through a CSRF-protected authenticated/throttled POST to `route('linkado.sso.launch')`. SSO requires live and accepts only the configured HTTPS host/effective port, not tracking CDN/proxy authorities. Never log/persist/attach analytics to the one-time redirect URL.

## Operate and verify

Run queue workers and Laravel's scheduler. Recovery is scheduled every five minutes and attribution pruning daily, with overlap protection. Use `linkado:health --json` and `linkado:diagnose --json`; offline checks do not verify remote program/scopes. Diagnose before `linkado:recover`, `linkado:retry` or `linkado:prune`. Manual retry requires an event ID and nonempty `--operator`/`--reason`, preserves the immutable snapshot and authorizes one audited attempt rather than unlimited recovery.

Test synthetic settings with HTTP fakes/stray-request prevention, both PHPUnit environment readers forced to safe values and an independent `APP_CONFIG_CACHE`; never inherit real credentials/endpoints/cache into ordinary tests. Shadow verifies local capture/recording. Validate actual hosted script loading/bytes/CSP/cookies, SSO, producers/transaction paths and financial behavior separately in controlled live acceptance. A local fixture or offline health result is not production readiness; browser processes receive public settings only.

## Upgrade and rollback

V2 is an explicit major upgrade from `^1.x`: preserve previous behavior through explicit mode/flags, change the constraint to `^2.0` only after release verification, and update in a branch. Manually merge published configuration with package defaults without `vendor:publish --force`; retain legacy URL semantics and remove old overrides only after verification. SDK v1.0.0 needs no separate release. Preserve source keys, existing snapshots and all four migration filenames/history.

V1.0 consumers must verify `2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php` is applied even without an adapter. V1.1 already includes it; v1.1 → v2 adds no fifth migration. Pause affected capture/registration for cutover, verify schema and health in shadow with features disabled, then complete integration acceptance before resuming. Off-mode health does not prove schema readiness. Never guess/backfill legacy identity associations.

After verified rollout, rebuild config cache and restart workers through the application's normal deployment workflow. Refresh this installed skill through `php artisan boost:update`; newly installed packages may require `--discover` according to installed Boost command help. Disable the affected integration before rollback; never drop migrations/history automatically or promise old code retains lifecycle guarantees.
