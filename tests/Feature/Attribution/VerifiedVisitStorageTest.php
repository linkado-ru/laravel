<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Actions\CapturePendingAttribution;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Tests\Support\Attribution\VerifiedVisit;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    config()->set([
        'linkado.mode' => 'live', 'linkado.token' => 'synthetic-test-token',
        'linkado.program_key' => 'synthetic-test-program', 'linkado.features.tracking' => true,
        'database.connections.verified_storage' => DatabaseConfiguration::externalOrSqlite(),
        'linkado.connection' => 'verified_storage',
    ]);
    DB::purge('verified_storage');
    $this->connection = DB::connection('verified_storage');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_10_20_075845_add_visit_order_to_linkado_pending_attributions_table.php')->up();
    $this->travelTo('2026-09-21 10:00:00');
    MockClient::destroyGlobal();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->down();
    DB::purge('verified_storage');
});

function storageVisit(string $source = 'first', string $visitor = '01ARZ3NDEKTSV4RRFFQ69G5FAX'): Request
{
    $request = VerifiedVisit::request($visitor, $source);
    Linkado::attribution()->prepare($request);

    return $request;
}

function storageCapture(Request $request): void
{
    app(CapturePendingAttribution::class)->handle($request, $request->cookie('linkado_visitor'));
}

it('refreshes the window for each newer confirmed click', function (int $seconds): void {
    // Arrange
    $mock = VerifiedVisit::fake();
    storageCapture(storageVisit());
    $this->travel($seconds)->seconds();

    // Act
    storageCapture(storageVisit('second'));

    // Assert
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    expect($row->click_id)->toBe(VerifiedVisit::click('second'))
        ->and($row->referral_slug)->toBeNull()
        ->and($row->captured_at)->toBe(now()->format('Y-m-d H:i:s'))
        ->and($row->expires_at)->toBe(now()->addSeconds(120)->format('Y-m-d H:i:s'));
    $mock->assertSentCount(2);
})->with([119, 120, 121]);

it('keeps a scrubbed marker and emits only one event after the caller commits', function (): void {
    // Arrange
    $mock = VerifiedVisit::fake();
    $request = storageVisit();
    storageCapture($request);
    $next = storageVisit('second');
    $events = [];
    app('events')->listen(AttributionConsumed::class, function (AttributionConsumed $event) use (&$events): void {
        $events[] = $event;
    });

    // Act
    $this->connection->transaction(function () use ($request, $next, &$events): void {
        expect(Linkado::attribution()->consume($request)?->clickId)->toBe(VerifiedVisit::click('first'));
        storageCapture($next);
        expect(Linkado::attribution()->consume($request))->toBeNull()->and($events)->toBeEmpty();
    });

    // Assert
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    expect($events)->toHaveCount(1)
        ->and($row->click_id)->toBeNull()->and($row->referral_slug)->toBeNull()
        ->and($row->consumed_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00');
    $mock->assertSentCount(2);
});

it('rolls back capture and consume with the caller and suppresses rolled back events', function (): void {
    // Arrange
    $mock = VerifiedVisit::fake();
    $first = storageVisit();
    $events = [];
    app('events')->listen(AttributionConsumed::class, function (AttributionConsumed $event) use (&$events): void {
        $events[] = $event;
    });

    // Act & Assert
    $this->connection->beginTransaction();
    storageCapture($first);
    $this->connection->rollBack();
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
    storageCapture($first);
    $next = storageVisit('second');
    $before = $this->connection->table('linkado_pending_attributions')->sole();
    $this->connection->beginTransaction();
    storageCapture($next);
    expect(Linkado::attribution()->consume($next)?->clickId)->toBe(VerifiedVisit::click('second'));
    $this->connection->rollBack();
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($before)
        ->and($events)->toBeEmpty();
    $mock->assertSentCount(2);
});

it('does not consume on or after server expiry', function (int $seconds): void {
    // Arrange
    $mock = VerifiedVisit::fake();
    $request = storageVisit();
    storageCapture($request);
    $this->travel($seconds)->seconds();

    // Act & Assert
    expect($this->connection->transaction(fn () => Linkado::attribution()->consume($request)))->toBeNull();
    $mock->assertSentCount(1);
})->with([120, 121]);

it('bounds capture retries and never replays the caller transaction', function (bool $outer): void {
    // Arrange
    $mock = VerifiedVisit::fake();
    $request = storageVisit();
    $attempts = 0;
    $this->connection->beforeExecuting(function (string $sql) use (&$attempts): void {
        if (str_starts_with($sql, 'select') && str_contains($sql, 'linkado_pending_attributions')) {
            $attempts++;

            throw new DeadlockException('deadlock detected: synthetic test failure');
        }
    });

    if ($outer) {
        $this->connection->beginTransaction();
    }

    // Act & Assert
    try {
        expect(fn () => storageCapture($request))->toThrow(DeadlockException::class);
        expect($attempts)->toBe($outer ? 1 : 3)
            ->and($this->connection->transactionLevel())->toBe($outer ? 1 : 0);
    } finally {
        if ($outer) {
            $this->connection->rollBack();
        }
    }
    $mock->assertSentCount(1);
})->with([false, true]);

it('rolls back a partial capture and propagates non concurrency errors without retry', function (): void {
    // Arrange
    $mock = VerifiedVisit::fake();
    $request = storageVisit();
    $updates = 0;
    $this->connection->beforeExecuting(function (string $sql) use (&$updates): void {
        if (str_starts_with($sql, 'update') && str_contains($sql, 'linkado_pending_attributions')) {
            $updates++;

            throw new RuntimeException('Synthetic storage failure');
        }
    });

    // Act & Assert
    expect(fn () => storageCapture($request))->toThrow(RuntimeException::class, 'Synthetic storage failure');
    expect($updates)->toBe(1)->and($this->connection->table('linkado_pending_attributions')->count())->toBe(0)
        ->and($this->connection->transactionLevel())->toBe(0);
    $mock->assertSentCount(1);
});

it('does not swallow an unrelated unique constraint failure and leaves the caller usable', function (): void {
    // Arrange
    $mock = VerifiedVisit::fake();
    storageCapture(storageVisit());
    $this->connection->getSchemaBuilder()->table('linkado_pending_attributions', function (Blueprint $table): void {
        $table->unique('created_at', 'unrelated_created_at_unique');
    });
    $request = storageVisit('second', '01ARZ3NDEKTSV4RRFFQ69G5FAY');
    $this->connection->beginTransaction();

    // Act & Assert
    try {
        expect(fn () => storageCapture($request))->toThrow(UniqueConstraintViolationException::class);
        expect($this->connection->transactionLevel())->toBe(1)
            ->and($this->connection->table('linkado_pending_attributions')->sole()->click_id)->toBe(VerifiedVisit::click('first'));
    } finally {
        $this->connection->rollBack();
    }
    $mock->assertSentCount(2);
});
