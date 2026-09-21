---
name: linkado-development
description: >
  Integrate linkado-ru/laravel tracking, attribution, SSO, and transactional
  event delivery into a Laravel 13 application using the package's public API.
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
