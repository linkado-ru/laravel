<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\ClaimOutboxEvent;
use Linkado\Laravel\Actions\CompleteOutboxClaim;
use Linkado\Laravel\Actions\DeliverClaimedOutboxEvent;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Delivery\ClaimedOutboxEvent;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;
use Linkado\PhpSdk\LinkadoConnector;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00');

    config()->set('database.connections.linkado_claim_test', DatabaseConfiguration::externalOrSqlite());
    config()->set('database.default', 'linkado_claim_test');
    config()->set('linkado.connection', 'linkado_claim_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.delivery.claim_timeout_seconds', 600);

    DB::purge('linkado_claim_test');

    p14OutboxMigration()->up();
    p14AttemptMigration()->up();
});

afterEach(function (): void {
    $connection = p14Connection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_claim_test')->hasTable('linkado_outbox_attempts')) {
        p14AttemptMigration()->down();
    }

    if (Schema::connection('linkado_claim_test')->hasTable('linkado_outbox_events')) {
        p14OutboxMigration()->down();
    }

    DB::purge('linkado_claim_test');
    CarbonImmutable::setTestNow();
});

it('atomically claims a due pending event and creates its matching attempt', function (): void {
    $event = p14Event(['next_attempt_at' => now()]);

    $claimed = p14Claimer()->claim((string) $event->event_id);

    expect($claimed)->toBeInstanceOf(ClaimedOutboxEvent::class)
        ->and($claimed?->eventId)->toBe($event->event_id)
        ->and($claimed?->eventType)->toBe($event->event_type)
        ->and($claimed?->payload)->toBe($event->payload)
        ->and($claimed?->payloadSha256)->toBe($event->payload_sha256)
        ->and($claimed?->attemptNumber)->toBe(1)
        ->and(Str::isUlid((string) $claimed?->claimToken))->toBeTrue()
        ->and($claimed?->createdAt->equalTo($event->created_at))->toBeTrue();

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($stored?->status)->toBe(OutboxStatus::Delivering)
        ->and($event->getKey())->toBeInt()
        ->and($stored?->attempt_count)->toBe(1)
        ->and($stored?->next_attempt_at)->toBeNull()
        ->and($stored?->claimed_at?->equalTo(now()))->toBeTrue()
        ->and($stored?->claim_token)->toBe($claimed?->claimToken)
        ->and($attempt->getKey())->toBeInt()
        ->and($attempt->outbox_event_id)->toBeInt()->toBe($event->getKey())
        ->and($attempt->number)->toBe(1)
        ->and($attempt->claim_token)->toBe($claimed?->claimToken)
        ->and($attempt->outcome)->toBeNull()
        ->and($attempt->started_at->equalTo(now()))->toBeTrue()
        ->and($attempt->finished_at)->toBeNull();
});

it('does not claim a pending event before its next attempt is due', function (): void {
    $event = p14Event(['next_attempt_at' => now()->addSecond()]);

    expect(p14Claimer()->claim((string) $event->event_id))->toBeNull()
        ->and($event->fresh()?->status)->toBe(OutboxStatus::Pending)
        ->and($event->fresh()?->attempt_count)->toBe(0)
        ->and(OutboxAttempt::query()->count())->toBe(0);
});

it('does not claim terminal or shadow events', function (OutboxStatus $status): void {
    $event = p14Event([
        'delivery_mode' => $status === OutboxStatus::Shadow ? DeliveryMode::Shadow : DeliveryMode::Live,
        'status' => $status,
        'terminal_at' => now(),
    ]);

    expect(p14Claimer()->claim((string) $event->event_id))->toBeNull()
        ->and($event->fresh()?->status)->toBe($status)
        ->and(OutboxAttempt::query()->count())->toBe(0);
})->with([
    'delivered' => OutboxStatus::Delivered,
    'shadow' => OutboxStatus::Shadow,
    'failed' => OutboxStatus::Failed,
]);

it('allows only one claimer to own a due event', function (): void {
    $event = p14Event();

    $first = p14Claimer()->claim((string) $event->event_id);
    $second = p14Claimer()->claim((string) $event->event_id);

    expect($first)->toBeInstanceOf(ClaimedOutboxEvent::class)
        ->and($second)->toBeNull()
        ->and($event->fresh()?->claim_token)->toBe($first?->claimToken)
        ->and(OutboxAttempt::query()->count())->toBe(1);
});

it('recovers an expired claim with a new token and attempt', function (): void {
    $oldToken = strtolower((string) Str::ulid());
    $event = p14Event([
        'status' => OutboxStatus::Delivering,
        'attempt_count' => 1,
        'claimed_at' => now()->subSeconds(601),
        'claim_token' => $oldToken,
    ]);
    p14Attempt($event, 1, $oldToken, now()->subSeconds(601));

    $claimed = p14Claimer()->claim((string) $event->event_id);

    $stored = $event->fresh();
    $attempts = OutboxAttempt::query()->orderBy('number')->get();

    expect($claimed)->toBeInstanceOf(ClaimedOutboxEvent::class)
        ->and($claimed?->attemptNumber)->toBe(2)
        ->and($claimed?->claimToken)->not->toBe($oldToken)
        ->and($stored?->status)->toBe(OutboxStatus::Delivering)
        ->and($stored?->attempt_count)->toBe(2)
        ->and($stored?->claim_token)->toBe($claimed?->claimToken)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->outcome)->toBe(AttemptOutcome::RetryScheduled)
        ->and($attempts[0]->finished_at?->equalTo(now()))->toBeTrue()
        ->and($attempts[1]->claim_token)->toBe($claimed?->claimToken)
        ->and($attempts[1]->outcome)->toBeNull()
        ->and($attempts[1]->finished_at)->toBeNull();
});

it('terminalizes a live event when the package mode changed to off', function (): void {
    $event = p14Event();
    config()->set('linkado.mode', DeliveryMode::Off->value);

    (new DeliverOutboxEvent((string) $event->event_id))->handle(
        p14Claimer(),
        app(DeliverClaimedOutboxEvent::class),
    );

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($stored?->status)->toBe(OutboxStatus::Failed)
        ->and($stored?->attempt_count)->toBe(1)
        ->and($stored?->claimed_at)->toBeNull()
        ->and($stored?->claim_token)->toBeNull()
        ->and($stored?->terminal_at?->equalTo(now()))->toBeTrue()
        ->and($attempt->number)->toBe(1)
        ->and($attempt->outcome)->toBe(AttemptOutcome::PackageDisabled)
        ->and($attempt->finished_at?->equalTo(now()))->toBeTrue()
        ->and(app()->resolved(LinkadoConnector::class))->toBeFalse();
});

it('completes state only for the current claim token', function (): void {
    $event = p14Event();
    $claimed = p14Claimer()->claim((string) $event->event_id);

    expect($claimed)->toBeInstanceOf(ClaimedOutboxEvent::class);

    $completed = p14Completer()->complete($claimed, function (OutboxEvent $lockedEvent, OutboxAttempt $attempt): void {
        $lockedEvent->status = OutboxStatus::Delivered;
        $lockedEvent->delivered_at = now();
        $lockedEvent->terminal_at = now();
        $attempt->outcome = AttemptOutcome::Delivered;
    });

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($completed)->toBeTrue()
        ->and($stored?->status)->toBe(OutboxStatus::Delivered)
        ->and($stored?->claim_token)->toBeNull()
        ->and($stored?->claimed_at)->toBeNull()
        ->and($stored?->delivered_at?->equalTo(now()))->toBeTrue()
        ->and($attempt->outcome)->toBe(AttemptOutcome::Delivered)
        ->and($attempt->finished_at?->equalTo(now()))->toBeTrue();
});

it('rejects stale completion after an expired claim is reclaimed', function (): void {
    $event = p14Event();
    $staleClaim = p14Claimer()->claim((string) $event->event_id);

    CarbonImmutable::setTestNow(now()->addSeconds(601));

    $currentClaim = p14Claimer()->claim((string) $event->event_id);
    $completionRan = false;
    $completed = p14Completer()->complete(
        $staleClaim,
        function (OutboxEvent $lockedEvent, OutboxAttempt $attempt) use (&$completionRan): void {
            $completionRan = true;
            $lockedEvent->status = OutboxStatus::Delivered;
            $attempt->outcome = AttemptOutcome::Delivered;
        },
    );

    $stored = $event->fresh();
    $attempts = OutboxAttempt::query()->orderBy('number')->get();

    expect($staleClaim)->toBeInstanceOf(ClaimedOutboxEvent::class)
        ->and($currentClaim)->toBeInstanceOf(ClaimedOutboxEvent::class)
        ->and($completed)->toBeFalse()
        ->and($completionRan)->toBeFalse()
        ->and($stored?->status)->toBe(OutboxStatus::Delivering)
        ->and($stored?->claim_token)->toBe($currentClaim?->claimToken)
        ->and($attempts[0]->outcome)->toBe(AttemptOutcome::RetryScheduled)
        ->and($attempts[1]->outcome)->toBeNull();
});

function p14Claimer(): ClaimOutboxEvent
{
    return app(ClaimOutboxEvent::class);
}

function p14Completer(): CompleteOutboxClaim
{
    return app(CompleteOutboxClaim::class);
}

/** @param array<string, mixed> $attributes */
function p14Event(array $attributes = []): OutboxEvent
{
    return OutboxEvent::factory()->create($attributes);
}

function p14Attempt(
    OutboxEvent $event,
    int $number,
    string $claimToken,
    CarbonInterface $startedAt,
): OutboxAttempt {
    return OutboxAttempt::factory()->create([
        'outbox_event_id' => $event->getKey(),
        'number' => $number,
        'claim_token' => $claimToken,
        'outcome' => null,
        'started_at' => $startedAt,
        'finished_at' => null,
    ]);
}

function p14Connection(): Connection
{
    return DB::connection('linkado_claim_test');
}

function p14OutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p14AttemptMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_linkado_outbox_attempts_table.php';
}

it('stops automatic claims at the attempt and time limits', function (string $boundary): void {
    $event = p14Event([
        'attempt_count' => $boundary === 'attempts' ? 8 : 1,
        'created_at' => $boundary === 'window' ? now()->subDay()->subSecond() : now(),
    ]);
    $claimToken = strtolower((string) Str::ulid());
    $event->status = OutboxStatus::Delivering;
    $event->claimed_at = now()->subSeconds(601);
    $event->claim_token = $claimToken;
    $event->save();
    p14Attempt($event, $event->attempt_count, $claimToken, now()->subSeconds(601));

    expect(p14Claimer()->claim($event->event_id))->toBeNull()
        ->and($event->fresh()?->status)->toBe(OutboxStatus::Failed)
        ->and($event->fresh()?->attempt_count)->toBe($event->attempt_count)
        ->and($event->fresh()?->claim_token)->toBeNull()
        ->and(OutboxAttempt::query()->sole()->outcome)->toBe(AttemptOutcome::Failed)
        ->and(OutboxAttempt::query()->sole()->finished_at)->not->toBeNull();
})->with(['attempts', 'window']);

it('does not send a queued event after switching to shadow mode', function (): void {
    $event = p14Event();
    config()->set('linkado.mode', DeliveryMode::Shadow->value);

    expect(p14Claimer()->claim($event->event_id))->toBeNull()
        ->and($event->fresh()?->status)->toBe(OutboxStatus::Failed)
        ->and(OutboxAttempt::query()->sole()->outcome)->toBe(AttemptOutcome::PackageDisabled);
});

it('never claims a shadow snapshot even if its status was changed to pending', function (): void {
    $event = p14Event(['delivery_mode' => DeliveryMode::Shadow]);

    expect(p14Claimer()->claim($event->event_id))->toBeNull()
        ->and(OutboxAttempt::query()->count())->toBe(0);
});

it('expires delayed queued retries before claiming them', function (): void {
    $event = p14Event(['attempt_count' => 1, 'created_at' => now()->subDay()->subSecond()]);

    expect(p14Claimer()->claim($event->event_id))->toBeNull()
        ->and($event->fresh()?->status)->toBe(OutboxStatus::Failed)
        ->and($event->fresh()?->attempt_count)->toBe(1);
});

it('permits one explicitly audited manual attempt beyond the automatic limits', function (): void {
    $event = p14Event(['attempt_count' => 9, 'created_at' => now()->subDays(2)]);
    OutboxAttempt::factory()->create([
        'outbox_event_id' => $event->getKey(),
        'number' => 9,
        'outcome' => AttemptOutcome::ManuallyRetried,
    ]);

    $claimed = p14Claimer()->claim($event->event_id);
    expect($claimed?->attemptNumber)->toBe(10);

    CarbonImmutable::setTestNow(now()->addSeconds(601));
    expect(p14Claimer()->claim($event->event_id))->toBeNull()
        ->and($event->fresh()?->status)->toBe(OutboxStatus::Failed);
});

it('terminalizes an unknown stored event type without resolving HTTP transport', function (): void {
    $event = p14Event();
    p14Connection()->table('linkado_outbox_events')->where('id', $event->id)->update(['event_type' => 'unknown_type']);

    expect(p14Claimer()->claim($event->event_id))->toBeNull()
        ->and($event->fresh()?->status)->toBe(OutboxStatus::Failed)
        ->and($event->fresh()?->getAttribute('last_error_code'))->toBe('payload_corrupt')
        ->and(OutboxAttempt::query()->sole()->outcome)->toBe(AttemptOutcome::Failed)
        ->and(OutboxAttempt::query()->sole()->getAttribute('error_code'))->toBe('payload_corrupt')
        ->and(app()->resolved(LinkadoConnector::class))->toBeFalse();
});
