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
2. The package defaults to `live`, tracking/customer/billing/refund enabled, SSO disabled, `via` and `5184000` seconds (60 days). Choose an explicit mode/flags while integrating; adding credentials to the default live configuration enables operations immediately. For local-only validation use `LINKADO_MODE=shadow` with a public program key; no token is required and no hosted script, SSO or outbox HTTP runs.
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

All competing claim paths lock host identity before pending attribution and hold the lock through outer commit. Unresolved/closed identities never fall back to visitor-only mode; capture requires the request and verified server facts. Do not adopt legacy unbound rows or expose bound rows by removing the adapter. Identity mismatch leaves the row untouched; successful claim prevents late captures after expiry/prune/visitor rotation.

## Verified last-valid visits

V3 always uses the last server-verified visit; no strategy environment variable or config flag is needed. It requires the authenticated resolver, PHP SDK `^1.1` and all five migrations, including `2026_10_20_075845_add_visit_order_to_linkado_pending_attributions_table.php`. Preserve their filenames/history. Prepare the request outside the host transaction with `Linkado::attribution()->prepare($request)`, then consume/claim/record inside the same connection transaction. Cookie sequence/expiry is untrusted; only authenticated server facts replace an unconsumed identity-bound candidate. Each newer click refreshes its own server window, including same-partner visits; repeated reads and invalid visits do not. Invalid atomic cookies have no fallback. Do not verify over HTTP inside a transaction or reevaluate cookies at delivery. Published v2.1 retains its old configuration contract; inspect the installed version before applying source-tree instructions.

## Tracking and SSO

Apply `linkado.attribution` to intended public landing routes and render `@linkadoTracking` once in the layout. Hosted rendering requires live readiness, eligibility, positive whole-day TTL and fixed `lk_click`/`lk_referral` source-cookie names. Server capture still supports second-level TTL. Markup keeps `defer`, `data-endpoint`, `data-program-key`, `data-referral-param`, `data-attribution-window-days`, and equal-valued old aliases `data-endpoint-url`/`data-referral-parameter`; never render the API token.

Slug/query-only values cannot establish attribution. When the atomic snapshot is absent, a syntactically valid click cookie can be authenticated by the resolver. A matching verified pending click may still be consumed during a resolver outage; an unverified or mismatched owned row is scrubbed into a marker. Foreign identity rows remain untouched. Browser cookie consistency alone never authenticates a click.

Bind `DeterminesLinkadoEligibility` for application policy; bind `ResolvesLinkadoSsoUser` before explicitly enabling SSO. Launch only through a CSRF-protected authenticated/throttled POST to `route('linkado.sso.launch')`. SSO requires live and accepts only the configured HTTPS host/effective port, not tracking CDN/proxy authorities. Never log/persist/attach analytics to the one-time redirect URL.

## Operate and verify

Run queue workers and Laravel's scheduler. Recovery is scheduled every five minutes and attribution pruning daily, with overlap protection. Use `linkado:health --json` and `linkado:diagnose --json`; offline checks do not verify remote program/scopes. Diagnose before `linkado:recover`, `linkado:retry` or `linkado:prune`. Manual retry requires an event ID and nonempty `--operator`/`--reason`, preserves the immutable snapshot and authorizes one audited attempt rather than unlimited recovery.

Test synthetic settings with HTTP fakes/stray-request prevention, both PHPUnit environment readers forced to safe values and an independent `APP_CONFIG_CACHE`; never inherit real credentials/endpoints/cache into ordinary tests. Shadow verifies local event recording without capturing new visits. Validate actual hosted script loading/bytes/CSP/cookies, SSO, producers/transaction paths and financial behavior separately in controlled live acceptance. A local fixture or offline health result is not production readiness; browser processes receive public settings only.

## Upgrade and rollback

V3 changes attribution behavior and removes the strategy setting. Published v2.1 users already have the required schema and PHP SDK; preserve all five migration files, source keys and snapshots. Remove the obsolete strategy override when consuming the new release. Earlier users must apply the existing identity and visit-order migrations through their normal migration process. Pause affected capture/registration during a coordinated upgrade; check schema/health and complete installed integration acceptance before resuming. Off-mode health does not prove schema readiness. Never infer verification or identity associations for historical rows. Keep API origin/base URL compatibility and unrelated feature flags unchanged.

After verified rollout, rebuild config cache and restart workers through the application's normal deployment workflow. Refresh this installed skill through `php artisan boost:update`; newly installed packages may require `--discover` according to installed Boost command help. Disable the affected integration before rollback; never drop migrations/history automatically or promise old code retains lifecycle guarantees.
