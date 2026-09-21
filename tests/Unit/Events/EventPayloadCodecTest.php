<?php

declare(strict_types=1);

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

it('encodes deterministic exact JSON with Unicode slashes and zero fractions', function (): void {
    $event = new CustomerCreatedEventData(
        event_id: '01K5NNNNNNNNNNNNNNNNNNNNNN',
        program_key: 'program/ru',
        occurred_at: '2026-09-21T10:11:12+03:00',
        external_customer_id: 'клиент/42',
        click_id: 'click/ёж',
        metadata: new EventMetadataData(
            source: 'партнёр/канал',
            billing_reason: 1.0,
        ),
    );
    $expected = '{"event_id":"01K5NNNNNNNNNNNNNNNNNNNNNN","type":"customer_created","program_key":"program/ru","occurred_at":"2026-09-21T10:11:12+03:00","external_customer_id":"клиент/42","click_id":"click/ёж","metadata":{"source":"партнёр/канал","billing_reason":1.0}}';

    $first = (new EventPayloadCodec)->encode($event);
    $second = (new EventPayloadCodec)->encode($event);

    expect($first->dtoClass)->toBe(CustomerCreatedEventData::class)
        ->and($first->type)->toBe(EventType::CustomerCreated)
        ->and($first->payload)->toBe($expected)
        ->and($first->sha256)->toBe('d170b7a93d821719f71e2805b4cbad2736d0fec1e8b1561b1b067e3f72b04dd2')
        ->and($second)->toEqual($first);
});

it('rejects payload bytes that do not survive canonical re-encoding', function (): void {
    $codec = new EventPayloadCodec;
    $encoded = $codec->encode(new CustomerCreatedEventData(
        event_id: '01K5NNNNNNNNNNNNNNNNNNNNN1',
        program_key: 'program',
        occurred_at: '2026-09-21T10:11:12Z',
        external_customer_id: 'customer-1',
    ));
    $tampered = str_replace(',"type"', ', "type"', $encoded->payload);

    expect(fn (): EventData => $codec->decode($encoded->dtoClass, $tampered))
        ->toThrow(UnexpectedValueException::class);
});

it('rejects DTO classes outside the official event allowlist', function (): void {
    $payload = '{"event_id":"01K5NNNNNNNNNNNNNNNNNNNNN2","type":"customer_created","program_key":"program","occurred_at":"2026-09-21T10:11:12Z","external_customer_id":"customer-2"}';

    expect(fn (): EventData => (new EventPayloadCodec)->decode(stdClass::class, $payload))
        ->toThrow(InvalidArgumentException::class);
});

it('rehydrates nested metadata through the official SDK DTO', function (): void {
    $codec = new EventPayloadCodec;
    $event = new PaymentSucceededEventData(
        event_id: '01K5NNNNNNNNNNNNNNNNNNNNN3',
        program_key: 'program',
        occurred_at: '2026-09-21T10:11:12Z',
        external_customer_id: 'customer-3',
        external_payment_id: 'payment-3',
        amount_minor: 9900,
        currency: 'RUB',
        payment_kind: PaymentKind::SubscriptionInitial,
        external_subscription_id: 'subscription-3',
        metadata: new EventMetadataData(source: 'checkout', plan_code: 'pro'),
    );
    $encoded = $codec->encode($event);

    $decoded = $codec->decode($encoded->dtoClass, $encoded->payload);

    expect($decoded)->toBeInstanceOf(PaymentSucceededEventData::class)
        ->and($decoded->metadata)->toBeInstanceOf(EventMetadataData::class)
        ->and($decoded->toArray())->toBe($event->toArray());
});

it('round trips every official event DTO', function (Closure $eventFactory, string $expectedClass, EventType $expectedType): void {
    $codec = new EventPayloadCodec;
    $event = $eventFactory();
    $encoded = $codec->encode($event);
    $decoded = $codec->decode($encoded->dtoClass, $encoded->payload);

    expect($encoded->dtoClass)->toBe($expectedClass)
        ->and($encoded->type)->toBe($expectedType)
        ->and($decoded)->toBeInstanceOf($expectedClass)
        ->and($decoded->toArray())->toBe($event->toArray())
        ->and($codec->encode($decoded))->toEqual($encoded);
})->with([
    'customer created' => [
        fn (): EventData => new CustomerCreatedEventData(
            event_id: '01K5NNNNNNNNNNNNNNNNNNNNN4',
            program_key: 'program',
            occurred_at: '2026-09-21T10:11:12Z',
            external_customer_id: 'customer-4',
            click_id: 'click-4',
        ),
        CustomerCreatedEventData::class,
        EventType::CustomerCreated,
    ],
    'lead created' => [
        fn (): EventData => new LeadCreatedEventData(
            event_id: '01K5NNNNNNNNNNNNNNNNNNNNN5',
            program_key: 'program',
            occurred_at: '2026-09-21T10:11:12Z',
            external_customer_id: 'customer-5',
            referral_slug: 'partner-5',
        ),
        LeadCreatedEventData::class,
        EventType::LeadCreated,
    ],
    'payment succeeded' => [
        fn (): EventData => new PaymentSucceededEventData(
            event_id: '01K5NNNNNNNNNNNNNNNNNNNNN6',
            program_key: 'program',
            occurred_at: '2026-09-21T10:11:12Z',
            external_customer_id: 'customer-6',
            external_payment_id: 'payment-6',
            amount_minor: 1500,
            currency: 'RUB',
            payment_kind: PaymentKind::OneTime,
        ),
        PaymentSucceededEventData::class,
        EventType::PaymentSucceeded,
    ],
    'payment refunded' => [
        fn (): EventData => new PaymentRefundedEventData(
            event_id: '01K5NNNNNNNNNNNNNNNNNNNNN7',
            program_key: 'program',
            occurred_at: '2026-09-21T10:11:12Z',
            external_customer_id: 'customer-7',
            external_payment_id: 'payment-7',
            external_refund_id: 'refund-7',
            refunded_amount_minor: 700,
            currency: 'RUB',
        ),
        PaymentRefundedEventData::class,
        EventType::PaymentRefunded,
    ],
    'subscription renewed' => [
        fn (): EventData => new SubscriptionRenewedEventData(
            event_id: '01K5NNNNNNNNNNNNNNNNNNNNN8',
            program_key: 'program',
            occurred_at: '2026-09-21T10:11:12Z',
            external_customer_id: 'customer-8',
            external_payment_id: 'payment-8',
            external_subscription_id: 'subscription-8',
            amount_minor: 2500,
            currency: 'RUB',
        ),
        SubscriptionRenewedEventData::class,
        EventType::SubscriptionRenewed,
    ],
    'subscription cancelled' => [
        fn (): EventData => new SubscriptionCancelledEventData(
            event_id: '01K5NNNNNNNNNNNNNNNNNNNNN9',
            program_key: 'program',
            occurred_at: '2026-09-21T10:11:12Z',
            external_customer_id: 'customer-9',
            external_subscription_id: 'subscription-9',
        ),
        SubscriptionCancelledEventData::class,
        EventType::SubscriptionCancelled,
    ],
]);
