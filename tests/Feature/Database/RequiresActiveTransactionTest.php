<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Linkado\Laravel\Exceptions\ActiveTransactionRequired;
use Linkado\Laravel\Support\Database\RequiresActiveTransaction;

it('rejects work outside a transaction on the package connection', function (): void {
    expect(fn (): ConnectionInterface => app(RequiresActiveTransaction::class)->ensure())
        ->toThrow(ActiveTransactionRequired::class);
});

it('rejects a transaction opened on a different connection', function (): void {
    config()->set('database.connections.linkado_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('linkado.connection', 'linkado_test');

    /** @var DatabaseManager $database */
    $database = app('db');
    $default = $database->connection();
    $default->beginTransaction();

    try {
        expect($default->transactionLevel())->toBe(1)
            ->and($database->connection('linkado_test')->transactionLevel())->toBe(0)
            ->and(fn (): ConnectionInterface => app(RequiresActiveTransaction::class)->ensure())
            ->toThrow(ActiveTransactionRequired::class);
    } finally {
        $default->rollBack();
    }
});

it('returns the configured connection from a nested transaction', function (): void {
    config()->set('database.connections.linkado_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('linkado.connection', 'linkado_test');

    /** @var DatabaseManager $database */
    $database = app('db');
    $connection = $database->connection('linkado_test');
    $connection->beginTransaction();
    $connection->beginTransaction();

    try {
        expect(app(RequiresActiveTransaction::class)->ensure())->toBe($connection)
            ->and($connection->transactionLevel())->toBe(2);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
});
