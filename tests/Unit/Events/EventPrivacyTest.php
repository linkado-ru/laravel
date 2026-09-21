<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Events\OutboxEventClaimed;
use Linkado\Laravel\Events\OutboxEventDelivered;
use Linkado\Laravel\Events\OutboxEventPermanentlyFailed;
use Linkado\Laravel\Events\OutboxEventRecorded;
use Linkado\Laravel\Events\OutboxEventRecovered;
use Linkado\Laravel\Events\OutboxPayloadConflictDetected;
use Linkado\Laravel\Events\OutboxRetryScheduled;
use Linkado\PhpSdk\DataObjects\EventData;

it('serializes lifecycle events without sensitive fields or exception objects', function (): void {
    $at = CarbonImmutable::parse('2026-09-21 12:00:00 UTC');
    $events = [
        new OutboxEventRecorded('event-1', 'source-1', OutboxStatus::Pending),
        new OutboxPayloadConflictDetected('source-1', 'event-1', 'event-2'),
        new OutboxEventClaimed('event-1', 'source-1', OutboxStatus::Delivering, 1, $at),
        new OutboxEventDelivered('event-1', 'source-1', OutboxStatus::Delivered, 1, $at),
        new OutboxRetryScheduled('event-1', 'source-1', OutboxStatus::Pending, 1, $at, 'http_503'),
        new OutboxEventPermanentlyFailed('event-1', 'source-1', OutboxStatus::Failed, 1, $at, 'payload_corrupt'),
        new OutboxEventRecovered('event-1', 'source-1', OutboxStatus::Pending, $at),
        new AttributionConsumed($at),
    ];

    foreach ($events as $event) {
        $reflection = new ReflectionClass($event);
        $serialized = json_encode($event, JSON_THROW_ON_ERROR);
        $properties = array_keys(get_object_vars($event));

        expect($serialized)
            ->not->toContain('token')
            ->not->toContain('url')
            ->not->toContain('cookie')
            ->not->toContain('email')
            ->not->toContain('ip')
            ->not->toContain('display');

        expect($properties)
            ->not->toContain('payload')
            ->not->toContain('token')
            ->not->toContain('url')
            ->not->toContain('cookie')
            ->not->toContain('email')
            ->not->toContain('ip')
            ->not->toContain('displayName');

        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();

            expect($type?->getName())
                ->not->toBe(Throwable::class)
                ->not->toBe(EventData::class);
        }
    }
})->covers([
    AttributionConsumed::class,
    OutboxEventClaimed::class,
    OutboxEventDelivered::class,
    OutboxEventPermanentlyFailed::class,
    OutboxEventRecovered::class,
    OutboxEventRecorded::class,
    OutboxPayloadConflictDetected::class,
    OutboxRetryScheduled::class,
]);

it('uses stable lifecycle enum and failure-code values', function (): void {
    $event = new OutboxRetryScheduled(
        eventId: 'event-1',
        sourceKey: 'source-1',
        status: OutboxStatus::Pending,
        attemptNumber: 2,
        nextAttemptAt: CarbonImmutable::parse('2026-09-21 12:01:00 UTC'),
        errorCode: 'http_429',
    );

    expect($event->status)->toBe(OutboxStatus::Pending)
        ->and($event->attemptNumber)->toBe(2)
        ->and($event->errorCode)->toBe('http_429')
        ->and(AttemptOutcome::RetryScheduled->value)->toBe('retry_scheduled');
});
