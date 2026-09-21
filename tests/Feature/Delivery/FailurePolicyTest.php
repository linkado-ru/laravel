<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\ClaimOutboxEvent;
use Linkado\Laravel\Actions\DeliverClaimedOutboxEvent;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Delivery\ClaimedOutboxEvent;
use Linkado\Laravel\Support\Delivery\RetrySchedule;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;
use Linkado\PhpSdk\LinkadoConnector;
use Linkado\PhpSdk\Requests\SendEventRequest;
use Mockery\MockInterface;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00 UTC');

    config()->set('database.connections.linkado_failure_policy_test', DatabaseConfiguration::externalOrSqlite());
    config()->set('database.default', 'linkado_failure_policy_test');
    config()->set('cache.default', 'array');
    config()->set('linkado.connection', 'linkado_failure_policy_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.queue', null);
    config()->set('linkado.token', 'test-credential');
    config()->set('linkado.base_url', 'https://linkado.test/api/v1');
    config()->set('linkado.delivery.max_attempts', 8);
    config()->set('linkado.delivery.claim_timeout_seconds', 600);
    config()->set('linkado.delivery.base_delay_seconds', 60);
    config()->set('linkado.delivery.max_delay_seconds', 21600);
    config()->set('linkado.delivery.retry_window_seconds', 86400);

    DB::purge('linkado_failure_policy_test');

    p16OutboxMigration()->up();
    p16AttemptMigration()->up();

    app()->instance(RetrySchedule::class, p16DeterministicRetrySchedule());
});

afterEach(function (): void {
    $connection = p16Connection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_failure_policy_test')->hasTable('linkado_outbox_attempts')) {
        p16AttemptMigration()->down();
    }

    if (Schema::connection('linkado_failure_policy_test')->hasTable('linkado_outbox_events')) {
        p16OutboxMigration()->down();
    }

    DB::purge('linkado_failure_policy_test');
    CarbonImmutable::setTestNow();
});

it('classifies HTTP failures and records a final attempt outcome', function (int $status, bool $retryable): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p16Event();
    $payload = $event->payload;
    $hash = $event->payload_sha256;
    p16Connector(new MockClient([
        SendEventRequest::class => MockResponse::make(
            body: ['error' => ['message' => 'secret response body']],
            status: $status,
        ),
    ]));

    expect(fn () => p16Deliver($event))->not->toThrow(Throwable::class);

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($stored?->event_id)->toBe($event->event_id)
        ->and($stored?->payload)->toBe($payload)
        ->and($stored?->payload_sha256)->toBe($hash)
        ->and($stored?->claimed_at)->toBeNull()
        ->and($stored?->claim_token)->toBeNull()
        ->and($stored?->getAttribute('last_error_code'))->toBe('http_'.$status)
        ->and($stored?->getAttribute('last_error_message'))->toBeNull()
        ->and($attempt->getAttribute('http_status'))->toBe($status)
        ->and($attempt->getAttribute('error_code'))->toBe('http_'.$status)
        ->and($attempt->getAttribute('error_message'))->toBeNull()
        ->and($attempt->finished_at?->equalTo(now()))->toBeTrue()
        ->and(json_encode([$stored?->getAttributes(), $attempt->getAttributes()], JSON_THROW_ON_ERROR))
        ->not->toContain('secret response body')
        ->not->toContain('test-credential');

    if ($retryable) {
        expect($stored?->status)->toBe(OutboxStatus::Pending)
            ->and($stored?->next_attempt_at?->equalTo(now()->addSeconds(60)))->toBeTrue()
            ->and($stored?->terminal_at)->toBeNull()
            ->and($attempt->outcome)->toBe(AttemptOutcome::RetryScheduled);
        Bus::assertDispatched(
            DeliverOutboxEvent::class,
            fn (DeliverOutboxEvent $job): bool => $job->eventId === $event->event_id
                && $job->delay instanceof DateTimeInterface
                && CarbonImmutable::instance($job->delay)->equalTo(now()->addSeconds(60)),
        );
    } else {
        expect($stored?->status)->toBe(OutboxStatus::Failed)
            ->and($stored?->next_attempt_at)->toBeNull()
            ->and($stored?->terminal_at?->equalTo(now()))->toBeTrue()
            ->and($attempt->outcome)->toBe(AttemptOutcome::Failed);
        Bus::assertNotDispatched(DeliverOutboxEvent::class);
    }
})->with([
    'bad request' => [400, false],
    'unauthorized' => [401, false],
    'forbidden' => [403, false],
    'not found' => [404, false],
    'request timeout' => [408, true],
    'conflict' => [409, false],
    'other client error' => [418, false],
    'unprocessable entity' => [422, false],
    'too many requests' => [429, true],
    'internal server error' => [500, true],
    'other server error' => [599, true],
]);

it('uses Retry-After seconds and dates for the next delayed job', function (string $header): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p16Event();
    p16Connector(new MockClient([
        SendEventRequest::class => MockResponse::make(
            body: ['error' => ['message' => 'throttled']],
            status: 429,
            headers: ['Retry-After' => $header],
        ),
    ]));

    p16Deliver($event);

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($stored?->status)->toBe(OutboxStatus::Pending)
        ->and($stored?->next_attempt_at?->equalTo(now()->addSeconds(300)))->toBeTrue()
        ->and($attempt->outcome)->toBe(AttemptOutcome::RetryScheduled)
        ->and($attempt->getAttribute('retry_after_seconds'))->toBe(300);
    Bus::assertDispatched(
        DeliverOutboxEvent::class,
        fn (DeliverOutboxEvent $job): bool => $job->delay instanceof DateTimeInterface
            && CarbonImmutable::instance($job->delay)->equalTo(now()->addSeconds(300)),
    );
})->with([
    'delta seconds' => '300',
    'HTTP date' => 'Mon, 21 Sep 2026 12:05:00 GMT',
]);

it('retries fatal request failures without persisting their message', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p16Event();
    p16Connector(new MockClient([
        SendEventRequest::class => MockResponse::make()->throw(
            fn (PendingRequest $request): FatalRequestException => new FatalRequestException(
                new RuntimeException('secret network address'),
                $request,
            ),
        ),
    ]));

    expect(fn () => p16Deliver($event))->not->toThrow(Throwable::class);

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($stored?->status)->toBe(OutboxStatus::Pending)
        ->and($stored?->getAttribute('last_error_code'))->toBe('request_failed')
        ->and($stored?->getAttribute('last_error_class'))->toBe(FatalRequestException::class)
        ->and($stored?->getAttribute('last_error_message'))->toBeNull()
        ->and($attempt->outcome)->toBe(AttemptOutcome::RetryScheduled)
        ->and($attempt->getAttribute('http_status'))->toBeNull()
        ->and($attempt->getAttribute('error_message'))->toBeNull()
        ->and(json_encode([$stored?->getAttributes(), $attempt->getAttributes()], JSON_THROW_ON_ERROR))
        ->not->toContain('secret network address');
    Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
});

it('fails retryable delivery on attempt eight without dispatching again', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p16Event(attemptCount: 7);
    p16Connector(new MockClient([
        SendEventRequest::class => MockResponse::make(['error' => []], 503),
    ]));

    p16Deliver($event);

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($stored?->attempt_count)->toBe(8)
        ->and($stored?->status)->toBe(OutboxStatus::Failed)
        ->and($stored?->next_attempt_at)->toBeNull()
        ->and($stored?->terminal_at?->equalTo(now()))->toBeTrue()
        ->and($attempt->number)->toBe(8)
        ->and($attempt->outcome)->toBe(AttemptOutcome::Failed)
        ->and($attempt->finished_at?->equalTo(now()))->toBeTrue();
    Bus::assertNotDispatched(DeliverOutboxEvent::class);
});

it('enforces the event retry-window boundary', function (int $eventAge, bool $retryable): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p16Event(createdAt: CarbonImmutable::now()->subSeconds($eventAge));
    p16Connector(new MockClient([
        SendEventRequest::class => MockResponse::make(['error' => []], 500),
    ]));

    p16Deliver($event);

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($stored?->status)->toBe($retryable ? OutboxStatus::Pending : OutboxStatus::Failed)
        ->and($attempt->outcome)->toBe($retryable ? AttemptOutcome::RetryScheduled : AttemptOutcome::Failed)
        ->and($attempt->finished_at?->equalTo(now()))->toBeTrue();

    if ($retryable) {
        expect($stored?->next_attempt_at?->equalTo(now()->addSeconds(60)))->toBeTrue();
        Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
    } else {
        expect($stored?->next_attempt_at)->toBeNull()
            ->and($stored?->terminal_at?->equalTo(now()))->toBeTrue();
        Bus::assertNotDispatched(DeliverOutboxEvent::class);
    }
})->with([
    'exactly twenty four hours' => [86340, true],
    'one second beyond twenty four hours' => [86341, false],
]);

it('dispatches the retry only after the completion transaction commits', function (): void {
    config()->set('linkado.queue', 'linkado-retries');
    $event = p16Event();
    p16Connector(new MockClient([
        SendEventRequest::class => MockResponse::make(['error' => []], 500),
    ]));
    $dispatcher = Mockery::mock(BusDispatcher::class, function (MockInterface $mock) use ($event): void {
        $mock->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (DeliverOutboxEvent $job) use ($event): bool {
                $stored = $event->fresh();
                $attempt = OutboxAttempt::query()->sole();

                expect(p16Connection()->transactionLevel())->toBe(0)
                    ->and($stored?->status)->toBe(OutboxStatus::Pending)
                    ->and($stored?->claim_token)->toBeNull()
                    ->and($attempt->outcome)->toBe(AttemptOutcome::RetryScheduled)
                    ->and($attempt->finished_at?->equalTo(now()))->toBeTrue()
                    ->and($job->queue)->toBe('linkado-retries')
                    ->and($job->delay instanceof DateTimeInterface)->toBeTrue();

                return true;
            })
            ->andReturn(1);
    });
    app()->instance(BusDispatcher::class, $dispatcher);

    p16Deliver($event);
});

it('does not let a stale failure schedule a retry for a replacement claim', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p16Event();
    $replacementClaim = null;
    p16Connector(new MockClient([
        SendEventRequest::class => function (PendingRequest $request) use ($event, &$replacementClaim): MockResponse {
            CarbonImmutable::setTestNow(now()->addSeconds(601));
            $replacementClaim = p16Claimer()->claim((string) $event->event_id);

            return MockResponse::make(['error' => []], 500);
        },
    ]));

    p16Deliver($event);

    $stored = $event->fresh();
    $attempts = OutboxAttempt::query()->orderBy('number')->get();

    expect($replacementClaim)->toBeInstanceOf(ClaimedOutboxEvent::class)
        ->and($stored?->status)->toBe(OutboxStatus::Delivering)
        ->and($stored?->claim_token)->toBe($replacementClaim?->claimToken)
        ->and($stored?->next_attempt_at)->toBeNull()
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->outcome)->toBe(AttemptOutcome::RetryScheduled)
        ->and($attempts[1]->outcome)->toBeNull()
        ->and($attempts[1]->finished_at)->toBeNull();
    Bus::assertNotDispatched(DeliverOutboxEvent::class);
});

function p16Deliver(OutboxEvent $event): void
{
    (new DeliverOutboxEvent((string) $event->event_id))->handle(
        p16Claimer(),
        app(DeliverClaimedOutboxEvent::class),
    );
}

function p16Claimer(): ClaimOutboxEvent
{
    return app(ClaimOutboxEvent::class);
}

function p16Connector(MockClient $mockClient): LinkadoConnector
{
    $connector = app(LinkadoConnector::class);
    $connector->withMockClient($mockClient);

    return $connector;
}

function p16Event(int $attemptCount = 0, ?CarbonImmutable $createdAt = null): OutboxEvent
{
    $eventData = new CustomerCreatedEventData(
        event_id: strtolower((string) Str::ulid()),
        program_key: 'program-public-key',
        occurred_at: '2026-09-20T12:00:00Z',
        external_customer_id: 'customer-123',
    );
    $payload = (new EventPayloadCodec)->encode($eventData);
    $createdAt ??= CarbonImmutable::now();

    return OutboxEvent::factory()->create([
        'event_id' => $eventData->event_id,
        'event_type' => $eventData->type(),
        'delivery_mode' => DeliveryMode::Live,
        'status' => OutboxStatus::Pending,
        'payload' => $payload->payload,
        'payload_sha256' => $payload->sha256,
        'attempt_count' => $attemptCount,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function p16DeterministicRetrySchedule(): RetrySchedule
{
    return new RetrySchedule(
        configuration: app(LinkadoConfiguration::class),
        jitter: static fn (int $minimum, int $maximum): int => $minimum,
    );
}

function p16Connection(): Connection
{
    return DB::connection('linkado_failure_policy_test');
}

function p16OutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p16AttemptMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_linkado_outbox_attempts_table.php';
}

it('persists oversized Retry-After as a portable terminal failure', function (): void {
    Bus::fake([DeliverOutboxEvent::class]);
    $event = p16Event();
    p16Connector(new MockClient([
        SendEventRequest::class => MockResponse::make([], 429, ['Retry-After' => '9223372036854775807']),
    ]));

    p16Deliver($event);

    expect($event->fresh()?->status)->toBe(OutboxStatus::Failed)
        ->and(OutboxAttempt::query()->sole()->getAttribute('retry_after_seconds'))->toBe(2147483647)
        ->and(OutboxAttempt::query()->sole()->outcome)->toBe(AttemptOutcome::Failed);
    Bus::assertNothingDispatched();
});
