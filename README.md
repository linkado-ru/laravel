# Linkado Laravel

Laravel 13 integration for the Linkado affiliate platform. The package captures referral attribution, creates Linkado SSO links, and delivers official Linkado SDK events through a transactional outbox.

## Requirements

- PHP 8.3 or later
- Laravel 13
- a queue worker for live event delivery
- Laravel's scheduler for automatic recovery and attribution pruning

## Installation

Install the package through Composer:

```bash
composer require linkado-ru/laravel
php artisan linkado:install
php artisan migrate
```

`linkado:install` publishes configuration, migrations, and translations. It is idempotent and does not run migrations. Published migration filenames remain stable, so later installs and publication do not create duplicate migrations. Use `php artisan linkado:install --force` only when you intend to overwrite already-published files.

The equivalent publish tags are:

```bash
php artisan vendor:publish --tag=linkado
php artisan vendor:publish --tag=linkado-config
php artisan vendor:publish --tag=linkado-migrations
php artisan vendor:publish --tag=linkado-lang
```

## Configuration

Start disabled, add the credential and public program key supplied by Linkado, then enable only the features the application has integrated:

```dotenv
LINKADO_MODE=shadow
LINKADO_TOKEN=integration-credential
LINKADO_PROGRAM_KEY=program-public-key
LINKADO_CUSTOMER_EVENTS_ENABLED=true
```

Never commit the credential. `shadow` mode is recommended while validating an integration because it records terminal local snapshots without sending outbox events. Tracking and SSO still follow their feature flags in shadow mode.

Every package configuration key is listed below. Values without an environment variable are intentionally changed in `config/linkado.php` after publishing.

| Configuration key | Environment variable / default | Purpose |
| --- | --- | --- |
| `linkado.mode` | `LINKADO_MODE=off` | Delivery mode: `off`, `shadow`, or `live`. |
| `linkado.connection` | `LINKADO_DB_CONNECTION=null` | Database connection that owns the host transaction and Linkado tables. `null` uses the default connection. |
| `linkado.queue` | `LINKADO_QUEUE=null` | Queue name for delivery jobs. `null` uses the connection's default queue. |
| `linkado.base_url` | `LINKADO_BASE_URL=https://app.linkado.ru/api/v1` | HTTPS Linkado API base URL without userinfo, query, or fragment. |
| `linkado.token` | `LINKADO_TOKEN=null` | Bearer credential used only when an SDK request is made. |
| `linkado.program_key` | `LINKADO_PROGRAM_KEY=null` | Public Linkado program key included in events and SSO requests. |
| `linkado.features.sso` | `LINKADO_SSO_ENABLED=false` | Enables SSO launches. |
| `linkado.features.tracking` | `LINKADO_TRACKING_ENABLED=false` | Enables tracking rendering and attribution capture. |
| `linkado.features.customer_events` | `LINKADO_CUSTOMER_EVENTS_ENABLED=false` | Enables customer-created and lead-created events. |
| `linkado.features.billing_events` | `LINKADO_BILLING_EVENTS_ENABLED=false` | Enables payment and subscription events. |
| `linkado.features.refund_events` | `LINKADO_REFUND_EVENTS_ENABLED=false` | Enables payment-refunded events. |
| `linkado.tracking.script_url` | `LINKADO_TRACKING_SCRIPT_URL=null` | Hosted tracking script URL. Required when tracking is rendered. |
| `linkado.tracking.endpoint_url` | `LINKADO_TRACKING_ENDPOINT_URL=null` | Hosted tracking endpoint exposed to the script. Required when tracking is rendered. |
| `linkado.tracking.referral_parameter` | `LINKADO_REFERRAL_PARAMETER=ref` | Referral query-string parameter. |
| `linkado.tracking.visitor_cookie` | `linkado_visitor` | Encrypted package visitor cookie name. |
| `linkado.tracking.click_cookie` | `lk_click` | Hosted script click-cookie name. |
| `linkado.tracking.referral_cookie` | `lk_referral` | Hosted script referral-cookie name. |
| `linkado.tracking.ttl_seconds` | `LINKADO_ATTRIBUTION_TTL_SECONDS=2592000` | Pending attribution lifetime in seconds. |
| `linkado.sso.route` | `linkado.sso.launch` | Name of the package's SSO POST route. |
| `linkado.sso.middleware` | `web`, `auth`, `throttle:6,1` | Middleware protecting SSO launches. |
| `linkado.sso.error_redirect` | `/` | Local path after an SSO failure; unsafe values fall back to `/`. |
| `linkado.delivery.max_attempts` | `8` | Maximum automatic delivery attempts. |
| `linkado.delivery.claim_timeout_seconds` | `600` | Time before an in-progress delivery claim is stale. |
| `linkado.delivery.base_delay_seconds` | `60` | Initial retry delay. |
| `linkado.delivery.max_delay_seconds` | `21600` | Maximum retry delay. |
| `linkado.delivery.retry_window_seconds` | `86400` | Maximum event age for automatic retries. |

## Delivery modes and features

`LINKADO_MODE` is an operational safety switch:

- `off` returns `null` without constructing an SDK event or writing a package row.
- `shadow` stores an immutable, terminal local snapshot and never dispatches HTTP delivery.
- `live` stores a pending event and dispatches its queue job only after the host transaction commits.

Feature flags are evaluated after the SDK DTO identifies its event family. The default eligibility policy allows enabled features. Applications can replace it with the `DeterminesLinkadoEligibility` contract:

```php
<?php

namespace App\Linkado;

use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;

final class LinkadoEligibility implements DeterminesLinkadoEligibility
{
    public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
    {
        return ! app()->environment('testing');
    }
}
```

Bind it in an application service provider:

```php
use App\Linkado\LinkadoEligibility;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;

$this->app->singleton(
    DeterminesLinkadoEligibility::class,
    LinkadoEligibility::class,
);
```

Eligibility exceptions fail closed: the package does not write or deliver the event.

## Attribution and event recording

Apply the `linkado.attribution` middleware to public landing routes that should capture Linkado click or referral values:

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'linkado.attribution'])->group(function (): void {
    Route::get('/pricing', fn () => view('pricing'));
    Route::get('/register', fn () => view('auth.register'));
});
```

The package manages its encrypted visitor cookie on the `web` middleware group. Capture gives click IDs precedence over referral slugs and stores only a SHA-256 visitor hash in the database. The first active touch fixes the attribution window: later captures do not replace it or extend its TTL. A referral slug may upgrade to a click, retaining the original capture and expiry times. At expiry (including the exact boundary), a new touch starts a new window.

Click IDs must pass Laravel's `Str::isUlid`; referral slugs must contain 1–100 lowercase ASCII letters or digits, optionally separated by single hyphens. Identifiers are never trimmed, truncated, or case-normalized. Null and empty strings mean absent; malformed source candidates reject capture or consumption without falling back to another source. For referral capture, an existing referral cookie takes precedence over the configured query parameter.

Consumption requires the visitor cookie and an exact matching source cookie: a click row requires the same click, and a referral row requires the same referral with no click candidate. Query parameters cannot confirm consumption, and consumption never upgrades a referral to a click. A stored row must contain exactly one valid source. Matching establishes consistency with the captured browser cookies; it does not verify that the click exists in Linkado.

Consume attribution and record the related SDK event inside the same transaction and on the connection configured by `linkado.connection`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Facades\Linkado;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;

DB::connection(config('linkado.connection'))->transaction(
    function () use ($request, $customer): void {
        /** @var Request $request */
        $attribution = Linkado::attribution()->consume($request);

        Linkado::record(
            sourceKey: 'customer-created:'.$customer->getKey(),
            eventFactory: fn (string $eventId): CustomerCreatedEventData => new CustomerCreatedEventData(
                event_id: $eventId,
                program_key: (string) config('linkado.program_key'),
                occurred_at: now(),
                external_customer_id: (string) $customer->getKey(),
                click_id: $attribution?->clickId,
                referral_slug: $attribution?->clickId === null
                    ? $attribution?->referralSlug
                    : null,
            ),
        );
    },
);
```

Successful consumption returns attribution once and clears its click/referral values. A consumed marker remains until the original expiry, preventing another capture in that window; the daily prune removes expired markers. Consumption and its after-commit event roll back with the caller transaction. No migration is required for these storage semantics.

Missing, malformed, or mismatched source cookies return `null` and discard attribution for the identified visitor, retaining the same scrubbed marker until expiry. Discard rolls back with the caller and emits no `AttributionConsumed` event. An invalid or unknown visitor cookie does not alter another visitor's row. Existing integrations must supply matching source cookies; visitor-only consumption no longer returns attribution.

The source key is the application's idempotency key. Repeating the same source key and payload returns the original row; reusing it for a different payload preserves the first row and emits a critical diagnostic event. Do not put email addresses, phone numbers, credentials, or other secrets in source keys.

Use the official `linkado-ru/php-sdk` DTOs for all supported event types:

- `CustomerCreatedEventData` and `LeadCreatedEventData`
- `PaymentSucceededEventData`
- `SubscriptionRenewedEventData` and `SubscriptionCancelledEventData`
- `PaymentRefundedEventData`

Amounts are positive integers in minor currency units. Never construct a package-specific replacement DTO, generate your own event ID inside the factory, or call Linkado HTTP APIs inside the host transaction.

## Tracking

Set the tracking feature and hosted URLs, then render the directive once in the page layout:

```dotenv
LINKADO_TRACKING_ENABLED=true
LINKADO_TRACKING_SCRIPT_URL=https://cdn.example.test/linkado.js
LINKADO_TRACKING_ENDPOINT_URL=https://tracking.example.test/events
```

```blade
<!doctype html>
<html lang="en">
    <head>
        @linkadoTracking
    </head>
    <body>
        {{ $slot }}
    </body>
</html>
```

The directive renders nothing when mode is `off`, tracking is disabled, or eligibility denies the request. Its two URLs must use HTTPS outside local/testing environments.

The script element keeps `defer` and emits `data-endpoint`, `data-program-key`, `data-referral-param`, and `data-attribution-window-days`. Compatibility aliases `data-endpoint-url` and `data-referral-parameter` contain the same endpoint and referral parameter. Values are HTML-escaped; the API token is never rendered.

Hosted rendering requires a public program key, a positive TTL that is an exact multiple of 86400 seconds, and the hosted script's fixed `lk_click` / `lk_referral` cookie names. Missing or incompatible configuration renders an empty string. The days attribute is derived from `linkado.tracking.ttl_seconds`; there is no separate browser TTL setting. Server storage still supports second-level TTL. The hosted response may change browser cookie expiry; it never extends an existing server attribution window.

Validate the actual hosted URL and bytes against the tested contract before deployment, then test loading, cookies and CSP in the consuming application. A locally served asset or the package's Node VM contract suite alone does not prove production browser integration.

The same tracking eligibility policy gates visitor-cookie issuance and attribution capture. It receives `LinkadoFeature::Tracking` with the current request, its current user, and no event. Mode and feature flags are checked before resolving the policy; policy resolution/evaluation failures disable tracking for that operation without breaking the host response. Invalid optional tracking configuration is also contained; downstream application and database failures still propagate. Tracking does not emit event eligibility diagnostics.

Keep the resolver read-only and arrange middleware so the required authentication and impersonation context is available before visitor issuance and capture. Admin and impersonation rules belong to the application. Decisions and request/user context are evaluated on each operation, not cached by the package. This policy check alone does not serialize attribution with concurrent registration or identity claims.

## Optional host identity locking

Applications with an anonymous identity lifecycle can bind `LocksLinkadoAttributionIdentity`. Without this binding, attribution remains visitor-only. The additive `AttributionIdentity` value object has `string $key` and `bool $captureAllowed`; the key must be a stable, opaque server-side identifier without PII. Only its namespaced SHA-256 hash is stored in `identity_hash`.

```php
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Contracts\LocksLinkadoAttributionIdentity;
use Linkado\Laravel\Support\Attribution\AttributionIdentity;

final class HostAttributionIdentity implements LocksLinkadoAttributionIdentity
{
    public function withLockedIdentity(Request $request, Closure $operation): void
    {
        // A trusted host middleware has already resolved this opaque key.
        // Do not use an untrusted cookie, query parameter, or cached lifecycle state.
        $key = $request->attributes->get('resolved_anonymous_key');
        if (! is_string($key)) {
            return;
        }

        $identity = DB::connection(config('linkado.connection'))
            ->table('anonymous_identities')->where('key', $key)
            ->lockForUpdate()->first();

        if ($identity !== null) {
            $operation(new AttributionIdentity($identity->key, ! $identity->claimed));
        }
    }
}

$this->app->bind(LocksLinkadoAttributionIdentity::class, HostAttributionIdentity::class);
```

The table and lifecycle belong to the application; the package does not create them. The adapter runs inside an active transaction on `linkado.connection`. It must re-read the host row under `FOR UPDATE` and invoke `Closure(AttributionIdentity): void` synchronously once while holding that lock, or not invoke it if identity is unresolved. It must not commit or roll back the caller's transaction, perform remote requests, or manage pending attribution. Use the same connection for the host identity and package tables. Resolve host context before the capture middleware; the separate eligibility policy remains read-only.

During registration, call `Linkado::attribution()->consume($request)` **before claiming the host identity**, inside the registration transaction on that connection. Call consume even when visitor/source cookies or pending attribution are absent and when customer events are disabled. The identity lock remains held until the host transaction ends. All competing host claim paths must follow this protocol. Lock order is host identity first, pending attribution second. A successful claim prevents later captures even after expiry, pruning, or visitor rotation; a rolled-back claim and consumption restore the previous state. No Linkado HTTP request is made on this path.

Identity bindings are checked for each operation. An unresolved or closed identity yields no capture or snapshot; it never falls back to visitor-only mode. The requestless `CapturePendingAttribution::handle()` refuses writes while an adapter is bound. Active rows cannot change identity. Identity mismatches return no attribution without scrubbing or otherwise modifying the row, even when source cookies mismatch. Legacy rows with `identity_hash = null` cannot be adopted by an adapter, and bound rows cannot be consumed after removing it. Once an old window expires, capture may create a new window under the current identity/lifecycle rules; existing visitors are never merged across devices.

For upgrade, pause attribution capture and related registration transitions, publish migrations and apply `2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php`, check `linkado:health`, then enable the adapter and resume. The three published create migrations remain unchanged. Do not backfill identity associations from guesses. For rollback, disable the affected integration first; removing an adapter or reverting package code does not preserve lifecycle guarantees, and the migration must not be dropped automatically.

## SSO

SSO is exposed only as the named POST route `linkado.sso.launch`. Launch it from a CSRF-protected form; do not link to the endpoint with GET:

```blade
<form method="POST" action="{{ route('linkado.sso.launch') }}">
    @csrf
    <button type="submit">Open Linkado</button>
</form>
```

The default middleware is `web`, `auth`, and `throttle:6,1`. Before enabling SSO, bind `ResolvesLinkadoSsoUser` to an application adapter:

```php
<?php

namespace App\Linkado;

use Illuminate\Contracts\Auth\Authenticatable;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Support\ResolvedSsoUser;
use Linkado\PhpSdk\Enums\SsoRedirect;

final class LinkadoSsoUserResolver implements ResolvesLinkadoSsoUser
{
    public function resolve(Authenticatable $user): ResolvedSsoUser
    {
        return new ResolvedSsoUser(
            externalUserId: (string) $user->getAuthIdentifier(),
            email: null,
            emailVerified: false,
            displayName: (string) $user->getAuthIdentifier(),
            redirectTo: SsoRedirect::AffiliatePortal,
        );
    }
}
```

```php
use App\Linkado\LinkadoSsoUserResolver;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;

$this->app->singleton(
    ResolvesLinkadoSsoUser::class,
    LinkadoSsoUserResolver::class,
);
```

Returned SSO URLs must use HTTPS with the configured hostname (case-insensitive) and effective port; omitted HTTPS port means 443. User-info, controls, backslashes and foreign authorities are rejected. SDK and resolver failures are replaced with a safe exception without an unsafe previous-exception chain. A successful one-time URL appears only in the response's `Location` header; the redirect body is empty.

Adapt the resolver to the host user model and supply verified email/display-name values where available. SSO performs its SDK request outside a database transaction. Failures return to `linkado.sso.error_redirect` with a translated validation error, and the one-time URL is never stored in the session or package tables.

## Queues and scheduler

Live delivery uses the application's queue connection. If `LINKADO_QUEUE=linkado`, run a worker that consumes that queue:

```bash
php artisan queue:work --queue=linkado
```

If `LINKADO_QUEUE` is unset, the job uses the connection's default queue. Horizon is optional; no dedicated Linkado worker implementation is required.

The service provider registers these overlap-protected schedules automatically:

- `linkado:recover` every five minutes
- `linkado:prune` daily

Run Laravel's scheduler in production and a worker locally when needed:

```bash
php artisan schedule:run
php artisan schedule:work
```

Only one scheduler command is needed on each invocation; the second form is intended for a long-running local process. Multi-node deployments should use a shared cache store so Laravel's overlap locks are shared.

## Operator commands

| Command | Purpose |
| --- | --- |
| `php artisan linkado:install [--force]` | Publish package resources without running migrations. |
| `php artisan linkado:health [--json]` | Check configuration, database access, migrations, queue lag, stale claims, and terminal failures. |
| `php artisan linkado:diagnose [--json]` | Report pending, stale, conflicting, exhausted, and corrupt events without changing state. |
| `php artisan linkado:recover [--json]` | Queue due or stalled delivery for recovery. |
| `php artisan linkado:retry {event} --operator= --reason= [--json]` | Retry one eligible failed event and record the operator audit fields. |
| `php artisan linkado:prune [--json]` | Delete expired pending attribution. |

Use `--json` for monitoring and automation. `linkado:health` exits non-zero when a failure check is present. Inspect with `linkado:diagnose` before manual retry; corrupt payloads and idempotency conflicts cannot be retried safely.

## Failure handling

The package persists exact JSON bytes and their SHA-256 hash, then rehydrates the same official SDK DTO for every attempt. Network failures, HTTP 408/429/5xx responses, and valid `Retry-After` values use bounded retries. Stable 4xx responses, HTTP 409 payload conflicts, attempt exhaustion, retry-window expiry, corruption, and disabling the package terminate delivery deterministically.

Automatic delivery is limited by `linkado.delivery.max_attempts` and `linkado.delivery.retry_window_seconds`. Recovery handles dispatch failures, stale claims, and lost queued jobs after the queue uniqueness lease expires (the configured claim timeout). A late worker cannot overwrite a newer claim. Switching from live to off or shadow prevents queued event delivery. A manual retry requires both `--operator` and `--reason`, never alters the original event ID or payload, and leaves an audit attempt. It authorizes one delivery even after automatic limits have expired; an abandoned manual attempt does not authorize unlimited recovery attempts.

Listen to package lifecycle events for application-specific observability. Their context is intentionally sanitized; do not attach raw DTO payloads, cookies, credentials, or SSO URLs in listeners.

## Privacy and data handling

- Pending attribution stores a visitor hash, an optional opaque identity hash, click/referral value, and timestamps. It stores no IP address, user agent, email, or host user foreign key.
- Outbox rows contain the official SDK payload. Send only identifiers and metadata allowed by the SDK; never add PII or secrets.
- Tracking cookies are package-scoped. Invalid or tampered visitor cookies are rotated without logging the raw value.
- Credentials are resolved lazily and excluded from configuration exceptions and connector debug output.
- SSO URLs contain one-time secrets and must not be logged, persisted, or added to analytics.

Review retention requirements for the host application and keep the daily attribution prune schedule active.

## Upgrading

The package follows Semantic Versioning. Within 1.x, additive configuration and migration changes may require publishing new resources; breaking public API changes are reserved for a new major version.

The unreleased changes since `v1.0.0` are a minor release candidate: the supported facade/contract signatures remain compatible and identity locking is opt-in. Security behavior is stricter: visitor-only consumption and malformed/mismatched source identifiers are rejected, and unsafe SSO authorities fail closed. See the [explicit API and schema diff](docs/compatibility.md).

The additive identity migration is required for upgraded capture/consume **even without an identity adapter**. Pause affected capture and registration transitions before changing package code, publish/apply the migration, then verify health in `shadow` mode with features disabled before resuming. `off` health deliberately marks database checks not applicable and is not migration proof. Do not guess or backfill legacy identity associations. Disable the affected integration before rollback; do not automatically remove the column or promise the old code's lifecycle safety.

Before upgrading:

1. Read [CHANGELOG.md](CHANGELOG.md).
2. Run `composer update linkado-ru/laravel linkado-ru/php-sdk` in a branch.
3. Compare the published `config/linkado.php` with the package default instead of overwriting local values blindly.
4. Publish any new migrations with `php artisan vendor:publish --tag=linkado-migrations` and run the application's normal migration process.
5. Run the application test suite and `php artisan linkado:health --json` in `shadow` mode before resuming capture/registration and enabling `live` mode.

## Development

Required CI covers Ubuntu, PHP 8.3/8.4/8.5 with lowest/stable dependencies, and MySQL 8.4, MariaDB 11.4 and PostgreSQL 17. CI runs on Ubuntu only; Windows is not part of the matrix.

See [the contribution guide](.github/CONTRIBUTING.md) for local validation and pull-request requirements. Security reports follow [the security policy](.github/SECURITY.md).

Linkado Laravel is open-source software licensed under the [MIT license](LICENSE.md).
