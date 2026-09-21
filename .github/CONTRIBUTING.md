# Contributing to Linkado Laravel

Linkado Laravel supports PHP 8.3+ and Laravel 13. Contributions should keep the package framework-native, portable across SQLite/MySQL/MariaDB/PostgreSQL, and independent from host domain models or Horizon.

## Local setup

```bash
git clone https://github.com/linkado-ru/laravel.git
cd laravel
composer install
composer build
```

The workbench is a disposable integration application. Do not add application-specific billing, user, or observability behavior to package code.

## Development workflow

1. Open an issue for material API, schema, dependency, or behavior changes.
2. Create a focused branch and write the smallest failing Pest/Testbench test first.
3. Implement against Laravel-native APIs and official `linkado-ru/php-sdk` DTOs.
4. Keep identifiers, source, tests, logs, and technical documentation in English. Add both English and Russian translations for user-facing validation or command errors.
5. Update README, changelog, workbench examples, and the bundled Boost skill when public behavior changes.

Run focused tests while iterating, then the full validation checks:

```bash
composer test:unit
composer analyse
composer lint:check
composer rector:check
composer test
composer build
git diff --check
```

The `attribution-concurrency` group requires a disposable MySQL/PostgreSQL database and independent PHP processes. It is excluded from the default SQLite suite and runs explicitly in every database CI job:

```bash
LINKADO_TEST_DB_DRIVER=mysql vendor/bin/pest --group=attribution-concurrency
LINKADO_TEST_DB_DRIVER=pgsql vendor/bin/pest --group=attribution-concurrency
```

Supply `LINKADO_TEST_DB_HOST`, `LINKADO_TEST_DB_PORT`, `LINKADO_TEST_DB_DATABASE`, `LINKADO_TEST_DB_USERNAME`, and `LINKADO_TEST_DB_PASSWORD` through the environment. An explicitly empty password is respected. Use only disposable test databases: the suite creates and drops package tables. The test account must be able to inspect lock waits (`performance_schema` for MySQL, `information_schema.INNODB_LOCK_WAITS` for MariaDB, `pg_blocking_pids` for PostgreSQL). Selecting this group without a server database fails rather than skips. Workers use pipe barriers and observed database lock waits, without timing sleeps.

Use `vendor/bin/pint --dirty --format agent` after PHP changes. The CI matrix supplies lowest/stable dependency, Windows, PHP-version, and external-database coverage that may not be available locally.

## Pull requests

Pull requests should:

- explain observable behavior and compatibility impact;
- include the test that failed before the implementation and passes after it;
- avoid unrelated refactors and speculative abstractions;
- preserve exact event payload bytes, idempotency, transaction boundaries, and PII-free diagnostics;
- document new configuration, commands, routes, publish tags, contracts, or operational requirements;
- pass the full validation suite without reducing type coverage.

Do not include credentials, production payloads, customer data, or one-time SSO URLs in issues, fixtures, logs, or screenshots.

By contributing, you agree that your contribution is licensed under the repository's MIT license.
