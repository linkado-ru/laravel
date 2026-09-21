<?php

declare(strict_types=1);

use Linkado\Laravel\Tests\Support\DatabaseConfiguration;

it('preserves an explicitly empty test database password', function (string $driver): void {
    $oldDriver = getenv('LINKADO_TEST_DB_DRIVER');
    $oldPassword = getenv('LINKADO_TEST_DB_PASSWORD');

    try {
        putenv('LINKADO_TEST_DB_DRIVER='.$driver);
        putenv('LINKADO_TEST_DB_PASSWORD=');

        expect(DatabaseConfiguration::externalOrSqlite()['password'])->toBe('');
    } finally {
        putenv($oldDriver === false ? 'LINKADO_TEST_DB_DRIVER' : 'LINKADO_TEST_DB_DRIVER='.$oldDriver);
        putenv($oldPassword === false ? 'LINKADO_TEST_DB_PASSWORD' : 'LINKADO_TEST_DB_PASSWORD='.$oldPassword);
    }
})->with(['mysql', 'pgsql']);
