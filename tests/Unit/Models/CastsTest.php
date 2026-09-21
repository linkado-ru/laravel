<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Linkado\Laravel\Database\Factories\OutboxAttemptFactory;
use Linkado\Laravel\Database\Factories\OutboxEventFactory;
use Linkado\Laravel\Database\Factories\PendingAttributionFactory;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Models\PendingAttribution;
use Linkado\PhpSdk\Enums\EventType;

it('uses configured connections and incrementing integer keys for every package model', function (): void {
    config()->set('linkado.connection', 'linkado_test');

    foreach ([new OutboxEvent, new OutboxAttempt, new PendingAttribution] as $model) {
        expect($model->getConnectionName())->toBe('linkado_test')
            ->and($model->getKeyType())->toBe('int')
            ->and($model->getIncrementing())->toBeTrue();
    }
});

it('casts outbox event state and dates to immutable package values', function (): void {
    $event = (new OutboxEvent)->forceFill([
        'event_type' => 'payment_succeeded',
        'delivery_mode' => 'shadow',
        'status' => 'pending',
        'attempt_count' => '2',
        'next_attempt_at' => '2026-09-21 10:00:00',
        'claimed_at' => '2026-09-21 10:01:00',
        'delivered_at' => '2026-09-21 10:02:00',
        'terminal_at' => '2026-09-21 10:03:00',
        'created_at' => '2026-09-21 09:59:00',
        'updated_at' => '2026-09-21 10:04:00',
    ]);

    expect($event->event_type)->toBe(EventType::PaymentSucceeded)
        ->and($event->delivery_mode)->toBe(DeliveryMode::Shadow)
        ->and($event->status)->toBe(OutboxStatus::Pending)
        ->and($event->attempt_count)->toBe(2);

    foreach (['next_attempt_at', 'claimed_at', 'delivered_at', 'terminal_at', 'created_at', 'updated_at'] as $attribute) {
        expect($event->getAttribute($attribute))->toBeInstanceOf(CarbonImmutable::class);
    }
});

it('casts attempt and attribution dates immutably', function (): void {
    $attempt = (new OutboxAttempt)->forceFill([
        'number' => '3',
        'outcome' => 'retry_scheduled',
        'http_status' => '429',
        'retry_after_seconds' => '120',
        'started_at' => '2026-09-21 10:00:00',
        'finished_at' => '2026-09-21 10:01:00',
        'created_at' => '2026-09-21 09:59:00',
        'updated_at' => '2026-09-21 10:02:00',
    ]);

    expect($attempt->number)->toBe(3)
        ->and($attempt->outcome)->toBe(AttemptOutcome::RetryScheduled)
        ->and($attempt->http_status)->toBe(429)
        ->and($attempt->retry_after_seconds)->toBe(120);

    foreach (['started_at', 'finished_at', 'created_at', 'updated_at'] as $attribute) {
        expect($attempt->getAttribute($attribute))->toBeInstanceOf(CarbonImmutable::class);
    }

    $attribution = (new PendingAttribution)->forceFill([
        'captured_at' => '2026-09-21 10:00:00',
        'expires_at' => '2026-10-21 10:00:00',
        'consumed_at' => '2026-09-22 10:00:00',
        'created_at' => '2026-09-21 09:59:00',
        'updated_at' => '2026-09-22 10:00:00',
    ]);

    foreach (['captured_at', 'expires_at', 'consumed_at', 'created_at', 'updated_at'] as $attribute) {
        expect($attribution->getAttribute($attribute))->toBeInstanceOf(CarbonImmutable::class);
    }
});

it('defines the event attempt relationships', function (): void {
    $event = new OutboxEvent;
    $attempt = new OutboxAttempt;

    expect($event->attempts())->toBeInstanceOf(HasMany::class)
        ->and($event->attempts()->getForeignKeyName())->toBe('outbox_event_id')
        ->and($attempt->event())->toBeInstanceOf(BelongsTo::class)
        ->and($attempt->event()->getForeignKeyName())->toBe('outbox_event_id');
});

it('guards immutable event fields while permitting lifecycle updates', function (): void {
    $event = (new OutboxEvent)->forceFill([
        'id' => 42,
        'event_id' => '01J00000000000000000000001',
        'source_key' => 'order:1',
        'event_type' => 'payment_succeeded',
        'delivery_mode' => 'live',
        'payload' => '{"event_id":"01J00000000000000000000001"}',
        'payload_sha256' => str_repeat('a', 64),
        'status' => 'pending',
    ]);
    $event->exists = true;

    $event->fill([
        'id' => 99,
        'event_id' => '01J99999999999999999999998',
        'source_key' => 'order:2',
        'event_type' => 'payment_refunded',
        'delivery_mode' => 'shadow',
        'payload' => '{}',
        'payload_sha256' => str_repeat('b', 64),
        'status' => 'failed',
        'attempt_count' => 4,
    ]);

    expect($event->id)->toBe(42)
        ->and($event->event_id)->toBe('01J00000000000000000000001')
        ->and($event->source_key)->toBe('order:1')
        ->and($event->event_type)->toBe(EventType::PaymentSucceeded)
        ->and($event->delivery_mode)->toBe(DeliveryMode::Live)
        ->and($event->payload)->toBe('{"event_id":"01J00000000000000000000001"}')
        ->and($event->payload_sha256)->toBe(str_repeat('a', 64))
        ->and($event->status)->toBe(OutboxStatus::Failed)
        ->and($event->attempt_count)->toBe(4);
});

it('provides usable factories for all storage models', function (): void {
    $event = OutboxEventFactory::new()->make();
    $attempt = OutboxAttemptFactory::new()->make([
        'outbox_event_id' => 42,
    ]);
    $attribution = PendingAttributionFactory::new()->make();

    expect($event)->toBeInstanceOf(OutboxEvent::class)
        ->and($event->delivery_mode)->toBeInstanceOf(DeliveryMode::class)
        ->and($event->status)->toBeInstanceOf(OutboxStatus::class)
        ->and($event->payload_sha256)->toHaveLength(64)
        ->and($attempt)->toBeInstanceOf(OutboxAttempt::class)
        ->and($attempt->outbox_event_id)->toBeInt()
        ->and($attempt->outcome)->toBeInstanceOf(AttemptOutcome::class)
        ->and($attribution)->toBeInstanceOf(PendingAttribution::class)
        ->and($attribution->visitor_hash)->toHaveLength(64);
});
