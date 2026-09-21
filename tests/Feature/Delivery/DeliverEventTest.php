<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Linkado\Laravel\Actions\ClaimOutboxEvent;
use Linkado\Laravel\Actions\DeliverClaimedOutboxEvent;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Delivery\ClaimedOutboxEvent;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;
use Linkado\PhpSdk\DataObjects\EventData;
use Linkado\PhpSdk\DataObjects\EventMetadataData;
use Linkado\PhpSdk\DataObjects\LeadCreatedEventData;
use Linkado\PhpSdk\DataObjects\PaymentRefundedEventData;
use Linkado\PhpSdk\DataObjects\PaymentSucceededEventData;
use Linkado\PhpSdk\DataObjects\SubscriptionCancelledEventData;
use Linkado\PhpSdk\DataObjects\SubscriptionRenewedEventData;
use Linkado\PhpSdk\Enums\EventType;
use Linkado\PhpSdk\Enums\PaymentKind;
use Linkado\PhpSdk\LinkadoConnector;
use Linkado\PhpSdk\Requests\SendEventRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00');

    config()->set('database.connections.linkado_delivery_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_delivery_test');
    config()->set('linkado.connection', 'linkado_delivery_test');
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.token', 'test-credential');
    config()->set('linkado.base_url', 'https://linkado.test/api/v1');
    config()->set('linkado.delivery.claim_timeout_seconds', 600);

    DB::purge('linkado_delivery_test');

    p15OutboxMigration()->up();
    p15AttemptMigration()->up();
});

afterEach(function (): void {
    $connection = p15Connection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_delivery_test')->hasTable('linkado_outbox_attempts')) {
        p15AttemptMigration()->down();
    }

    if (Schema::connection('linkado_delivery_test')->hasTable('linkado_outbox_events')) {
        p15OutboxMigration()->down();
    }

    DB::purge('linkado_delivery_test');
    CarbonImmutable::setTestNow();
});

it('delivers the exact stored JSON for every official event DTO and persists the accepted result', function (EventType $type): void {
    $eventData = p15EventData($type);
    $expectedBody = p15ExpectedBody($type);
    $event = p15Event($eventData);
    $storedPayload = $event->payload;
    $storedHash = $event->payload_sha256;
    $mockClient = new MockClient([
        SendEventRequest::class => MockResponse::make(
            body: p15AcceptedResponse($eventData->event_id, ['duplicate_ignored']),
            status: 202,
        ),
    ]);
    p15Connector($mockClient);

    p15Deliver($event);

    $request = $mockClient->getLastPendingRequest();
    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($request?->getRequest())->toBeInstanceOf(SendEventRequest::class)
        ->and($request?->body()?->all())->toBe($expectedBody)
        ->and($request?->getUrl())->toBe('https://linkado.test/api/v1/events')
        ->and($mockClient->getRecordedResponses())->toHaveCount(1)
        ->and(json_decode($storedPayload, true, flags: JSON_THROW_ON_ERROR))->toBe($expectedBody)
        ->and($stored?->payload)->toBe($storedPayload)
        ->and($stored?->payload_sha256)->toBe($storedHash)
        ->and($stored?->status)->toBe(OutboxStatus::Delivered)
        ->and($stored?->delivered_at?->equalTo(now()))->toBeTrue()
        ->and($stored?->terminal_at?->equalTo(now()))->toBeTrue()
        ->and($stored?->claimed_at)->toBeNull()
        ->and($stored?->claim_token)->toBeNull()
        ->and($stored?->getAttribute('remote_event_id'))->toBe('01k5g7a5skz8f7gj0zc05aq8c2')
        ->and($stored?->getAttribute('remote_status'))->toBe('accepted')
        ->and($stored?->getAttribute('remote_warnings'))->toBe('["duplicate_ignored"]')
        ->and($attempt->outcome)->toBe(AttemptOutcome::Delivered)
        ->and($attempt->finished_at?->equalTo(now()))->toBeTrue()
        ->and($attempt->getAttribute('remote_event_id'))->toBe('01k5g7a5skz8f7gj0zc05aq8c2')
        ->and($attempt->getAttribute('remote_status'))->toBe('accepted')
        ->and($attempt->getAttribute('remote_warnings'))->toBe('["duplicate_ignored"]');
})->with([
    'customer created' => EventType::CustomerCreated,
    'lead created' => EventType::LeadCreated,
    'payment succeeded' => EventType::PaymentSucceeded,
    'subscription renewed' => EventType::SubscriptionRenewed,
    'payment refunded' => EventType::PaymentRefunded,
    'subscription cancelled' => EventType::SubscriptionCancelled,
]);

it('permanently rejects corrupt stored events before making an HTTP request', function (string $corruption): void {
    $event = p15Event(p15EventData(EventType::CustomerCreated));
    p15Corrupt($event, $corruption);
    $mockClient = new MockClient([
        SendEventRequest::class => MockResponse::make(
            body: p15AcceptedResponse((string) $event->event_id),
            status: 202,
        ),
    ]);
    p15Connector($mockClient);

    p15Deliver($event);

    $stored = $event->fresh();
    $attempt = OutboxAttempt::query()->sole();

    expect($mockClient->getRecordedResponses())->toHaveCount(0)
        ->and($stored?->status)->toBe(OutboxStatus::Failed)
        ->and($stored?->claimed_at)->toBeNull()
        ->and($stored?->claim_token)->toBeNull()
        ->and($stored?->terminal_at?->equalTo(now()))->toBeTrue()
        ->and($stored?->getAttribute('last_error_code'))->toBe('payload_corrupt')
        ->and($stored?->getAttribute('last_error_class'))->toBeNull()
        ->and($stored?->getAttribute('last_error_message'))->toBeNull()
        ->and($attempt->outcome)->toBe(AttemptOutcome::Failed)
        ->and($attempt->finished_at?->equalTo(now()))->toBeTrue()
        ->and($attempt->getAttribute('error_code'))->toBe('payload_corrupt')
        ->and($attempt->getAttribute('error_class'))->toBeNull()
        ->and($attempt->getAttribute('error_message'))->toBeNull();
})->with([
    'hash mismatch' => 'hash',
    'non-canonical payload bytes' => 'bytes',
    'payload type outside the stored type mapping' => 'type',
    'payload event ID mismatch' => 'event_id',
]);

it('does not let a stale delivery result overwrite a replacement claim', function (): void {
    $eventData = p15EventData(EventType::PaymentRefunded);
    $event = p15Event($eventData);
    $replacementClaim = null;
    $mockClient = new MockClient([
        SendEventRequest::class => function (PendingRequest $request) use ($event, $eventData, &$replacementClaim): MockResponse {
            CarbonImmutable::setTestNow(now()->addSeconds(601));
            $replacementClaim = p15Claimer()->claim((string) $event->event_id);

            return MockResponse::make(
                body: p15AcceptedResponse($eventData->event_id),
                status: 202,
            );
        },
    ]);
    p15Connector($mockClient);

    p15Deliver($event);

    $stored = $event->fresh();
    $attempts = OutboxAttempt::query()->orderBy('number')->get();

    expect($replacementClaim)->toBeInstanceOf(ClaimedOutboxEvent::class)
        ->and($mockClient->getRecordedResponses())->toHaveCount(1)
        ->and($stored?->status)->toBe(OutboxStatus::Delivering)
        ->and($stored?->claim_token)->toBe($replacementClaim?->claimToken)
        ->and($stored?->delivered_at)->toBeNull()
        ->and($stored?->terminal_at)->toBeNull()
        ->and($stored?->getAttribute('remote_event_id'))->toBeNull()
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->outcome)->toBe(AttemptOutcome::RetryScheduled)
        ->and($attempts[0]->getAttribute('remote_event_id'))->toBeNull()
        ->and($attempts[1]->outcome)->toBeNull()
        ->and($attempts[1]->finished_at)->toBeNull();
});

function p15Deliver(OutboxEvent $event): void
{
    (new DeliverOutboxEvent((string) $event->event_id))->handle(
        p15Claimer(),
        app(DeliverClaimedOutboxEvent::class),
    );
}

function p15Claimer(): ClaimOutboxEvent
{
    return app(ClaimOutboxEvent::class);
}

function p15Connector(MockClient $mockClient): LinkadoConnector
{
    $connector = app(LinkadoConnector::class);
    $connector->withMockClient($mockClient);

    return $connector;
}

function p15Event(EventData $eventData): OutboxEvent
{
    $payload = (new EventPayloadCodec)->encode($eventData);

    return OutboxEvent::factory()->create([
        'event_id' => $eventData->event_id,
        'event_type' => $eventData->type(),
        'delivery_mode' => DeliveryMode::Live,
        'status' => OutboxStatus::Pending,
        'payload' => $payload->payload,
        'payload_sha256' => $payload->sha256,
        'attempt_count' => 0,
    ]);
}

function p15Corrupt(OutboxEvent $event, string $corruption): void
{
    if ($corruption === 'hash') {
        $event->setAttribute('payload_sha256', str_repeat('0', 64));
        $event->save();

        return;
    }

    if ($corruption === 'bytes') {
        $payload = $event->payload."\n";
        $event->setAttribute('payload', $payload);
        $event->setAttribute('payload_sha256', hash('sha256', $payload));
        $event->save();

        return;
    }

    if ($corruption === 'type') {
        $event->setAttribute('event_type', EventType::LeadCreated);
        $event->save();

        return;
    }

    $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['event_id'] = '01k5g6xybn7qpf9g0ajm1t2e3s';
    $encoded = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
    );

    $event->setAttribute('payload', $encoded);
    $event->setAttribute('payload_sha256', hash('sha256', $encoded));
    $event->save();
}

/** @return array<string, mixed> */
function p15AcceptedResponse(string $eventId, array $warnings = []): array
{
    return [
        'data' => [
            'id' => '01k5g7a5skz8f7gj0zc05aq8c2',
            'event_id' => $eventId,
            'status' => 'accepted',
            'result' => null,
            'warnings' => $warnings,
        ],
    ];
}

function p15EventData(EventType $type): EventData
{
    $common = [
        'event_id' => '01k5g6xybn7qpf9g0ajm1t2e3r',
        'program_key' => 'program-public-key',
        'occurred_at' => new DateTimeImmutable('2026-09-20T15:00:00+03:00'),
        'external_customer_id' => 'customer-123',
        'metadata' => new EventMetadataData(
            source: 'package-test',
            source_event: 'delivery',
        ),
    ];

    return match ($type) {
        EventType::CustomerCreated => new CustomerCreatedEventData(
            ...$common,
            click_id: '01k5g70w42mjjvp4gqj3a3rn01',
        ),
        EventType::LeadCreated => new LeadCreatedEventData(
            ...$common,
            referral_slug: 'partner-one',
        ),
        EventType::PaymentSucceeded => new PaymentSucceededEventData(
            ...$common,
            external_payment_id: 'invoice-100',
            amount_minor: 159900,
            currency: 'RUB',
            payment_kind: PaymentKind::SubscriptionInitial,
            external_subscription_id: 'subscription-10',
        ),
        EventType::SubscriptionRenewed => new SubscriptionRenewedEventData(
            ...$common,
            external_payment_id: 'invoice-101',
            external_subscription_id: 'subscription-10',
            amount_minor: 159900,
            currency: 'RUB',
        ),
        EventType::PaymentRefunded => new PaymentRefundedEventData(
            ...$common,
            external_payment_id: 'invoice-101',
            external_refund_id: 'refund-delta-1',
            refunded_amount_minor: 50000,
            currency: 'RUB',
        ),
        EventType::SubscriptionCancelled => new SubscriptionCancelledEventData(
            ...$common,
            external_subscription_id: 'subscription-10',
        ),
    };
}

/** @return array<string, mixed> */
function p15ExpectedBody(EventType $type): array
{
    $common = [
        'event_id' => '01k5g6xybn7qpf9g0ajm1t2e3r',
        'type' => $type->value,
        'program_key' => 'program-public-key',
        'occurred_at' => '2026-09-20T12:00:00+00:00',
        'external_customer_id' => 'customer-123',
    ];
    $metadata = [
        'metadata' => [
            'source' => 'package-test',
            'source_event' => 'delivery',
        ],
    ];

    return match ($type) {
        EventType::CustomerCreated => [
            ...$common,
            'click_id' => '01k5g70w42mjjvp4gqj3a3rn01',
            ...$metadata,
        ],
        EventType::LeadCreated => [
            ...$common,
            'referral_slug' => 'partner-one',
            ...$metadata,
        ],
        EventType::PaymentSucceeded => [
            ...$common,
            'external_payment_id' => 'invoice-100',
            'amount_minor' => 159900,
            'currency' => 'RUB',
            'payment_kind' => 'subscription_initial',
            'external_subscription_id' => 'subscription-10',
            ...$metadata,
        ],
        EventType::SubscriptionRenewed => [
            ...$common,
            'external_payment_id' => 'invoice-101',
            'external_subscription_id' => 'subscription-10',
            'amount_minor' => 159900,
            'currency' => 'RUB',
            ...$metadata,
        ],
        EventType::PaymentRefunded => [
            ...$common,
            'external_payment_id' => 'invoice-101',
            'external_refund_id' => 'refund-delta-1',
            'refunded_amount_minor' => 50000,
            'currency' => 'RUB',
            ...$metadata,
        ],
        EventType::SubscriptionCancelled => [
            ...$common,
            'external_subscription_id' => 'subscription-10',
            ...$metadata,
        ],
    };
}

function p15Connection(): Connection
{
    return DB::connection('linkado_delivery_test');
}

function p15OutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p15AttemptMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000001_create_linkado_outbox_attempts_table.php';
}
