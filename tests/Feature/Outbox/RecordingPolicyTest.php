<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Linkado\Laravel\Actions\RecordLinkadoEvent;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\EligibilityEvaluationFailed;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;
use Linkado\PhpSdk\DataObjects\EventData;
use Linkado\PhpSdk\DataObjects\LeadCreatedEventData;
use Linkado\PhpSdk\DataObjects\PaymentRefundedEventData;
use Linkado\PhpSdk\DataObjects\PaymentSucceededEventData;
use Linkado\PhpSdk\DataObjects\SubscriptionCancelledEventData;
use Linkado\PhpSdk\DataObjects\SubscriptionRenewedEventData;
use Linkado\PhpSdk\Enums\EventType;
use Linkado\PhpSdk\Enums\PaymentKind;

beforeEach(function (): void {
    config()->set('database.connections.linkado_policy_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'linkado_policy_test');
    config()->set('linkado.connection', 'linkado_policy_test');

    DB::purge('linkado_policy_test');

    p8OutboxMigration()->up();
});

afterEach(function (): void {
    $connection = p8Connection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    if (Schema::connection('linkado_policy_test')->hasTable('linkado_outbox_events')) {
        p8OutboxMigration()->down();
    }

    DB::purge('linkado_policy_test');
});

it('applies every mode feature and eligibility combination', function (
    DeliveryMode $mode,
    bool $featureEnabled,
    bool $eligible,
    int $expectedFactoryCalls,
    int $expectedEligibilityCalls,
    ?OutboxStatus $expectedStatus,
): void {
    config()->set('linkado.mode', $mode->value);
    config()->set('linkado.features.customer_events', $featureEnabled);

    $eligibility = new class($eligible) implements DeterminesLinkadoEligibility
    {
        public int $calls = 0;

        public function __construct(private readonly bool $eligible) {}

        public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
        {
            $this->calls++;

            return $this->eligible;
        }
    };
    app()->instance(DeterminesLinkadoEligibility::class, $eligibility);

    $factoryCalls = 0;
    p8Connection()->beginTransaction();

    $recorded = p8Recorder()->handle('customer:policy', function (string $eventId) use (&$factoryCalls): EventData {
        $factoryCalls++;

        return p8Event(EventType::CustomerCreated, $eventId);
    });

    expect($factoryCalls)->toBe($expectedFactoryCalls)
        ->and($eligibility->calls)->toBe($expectedEligibilityCalls)
        ->and($recorded?->status)->toBe($expectedStatus)
        ->and(OutboxEvent::query()->count())->toBe($expectedStatus instanceof OutboxStatus ? 1 : 0);

    if ($expectedStatus === OutboxStatus::Shadow) {
        expect($recorded?->delivery_mode)->toBe(DeliveryMode::Shadow)
            ->and($recorded?->terminal_at)->not->toBeNull();
    }

    if ($expectedStatus === OutboxStatus::Pending) {
        expect($recorded?->delivery_mode)->toBe(DeliveryMode::Live)
            ->and($recorded?->terminal_at)->toBeNull();
    }
})->with([
    'off, feature disabled, eligibility denies' => [DeliveryMode::Off, false, false, 0, 0, null],
    'off, feature disabled, eligibility allows' => [DeliveryMode::Off, false, true, 0, 0, null],
    'off, feature enabled, eligibility denies' => [DeliveryMode::Off, true, false, 0, 0, null],
    'off, feature enabled, eligibility allows' => [DeliveryMode::Off, true, true, 0, 0, null],
    'shadow, feature disabled, eligibility denies' => [DeliveryMode::Shadow, false, false, 1, 0, null],
    'shadow, feature disabled, eligibility allows' => [DeliveryMode::Shadow, false, true, 1, 0, null],
    'shadow, feature enabled, eligibility denies' => [DeliveryMode::Shadow, true, false, 1, 1, null],
    'shadow, feature enabled, eligibility allows' => [DeliveryMode::Shadow, true, true, 1, 1, OutboxStatus::Shadow],
    'live, feature disabled, eligibility denies' => [DeliveryMode::Live, false, false, 1, 0, null],
    'live, feature disabled, eligibility allows' => [DeliveryMode::Live, false, true, 1, 0, null],
    'live, feature enabled, eligibility denies' => [DeliveryMode::Live, true, false, 1, 1, null],
    'live, feature enabled, eligibility allows' => [DeliveryMode::Live, true, true, 1, 1, OutboxStatus::Pending],
]);

it('maps every SDK event type to its recording feature', function (EventType $type, LinkadoFeature $expectedFeature): void {
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.features.'.$expectedFeature->value, true);

    $eligibility = new class implements DeterminesLinkadoEligibility
    {
        public ?LinkadoFeature $feature = null;

        public ?EventData $event = null;

        public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
        {
            $this->feature = $feature;
            $this->event = $context->event;

            return false;
        }
    };
    app()->instance(DeterminesLinkadoEligibility::class, $eligibility);
    p8Connection()->beginTransaction();

    $recorded = p8Recorder()->handle(
        'event:'.$type->value,
        fn (string $eventId): EventData => p8Event($type, $eventId),
    );

    expect($recorded)->toBeNull()
        ->and($eligibility->feature)->toBe($expectedFeature)
        ->and($eligibility->event?->type())->toBe($type)
        ->and(OutboxEvent::query()->count())->toBe(0);
})->with([
    'customer created' => [EventType::CustomerCreated, LinkadoFeature::CustomerEvents],
    'lead created' => [EventType::LeadCreated, LinkadoFeature::CustomerEvents],
    'payment succeeded' => [EventType::PaymentSucceeded, LinkadoFeature::BillingEvents],
    'subscription renewed' => [EventType::SubscriptionRenewed, LinkadoFeature::BillingEvents],
    'subscription cancelled' => [EventType::SubscriptionCancelled, LinkadoFeature::BillingEvents],
    'payment refunded' => [EventType::PaymentRefunded, LinkadoFeature::RefundEvents],
]);

it('fails closed before invoking the factory when the configured mode is invalid', function (): void {
    config()->set('linkado.mode', 'invalid');
    $factoryCalls = 0;
    p8Connection()->beginTransaction();

    expect(fn (): ?OutboxEvent => p8Recorder()->handle(
        'customer:invalid-mode',
        function (string $eventId) use (&$factoryCalls): EventData {
            $factoryCalls++;

            return p8Event(EventType::CustomerCreated, $eventId);
        },
    ))->toThrow(InvalidLinkadoConfiguration::class);

    expect($factoryCalls)->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0)
        ->and(p8Connection()->transactionLevel())->toBe(1);
});

it('contains eligibility failures without writing or exposing PII in the diagnostic event', function (): void {
    config()->set('linkado.mode', DeliveryMode::Live->value);
    config()->set('linkado.features.customer_events', true);
    Event::fake([EligibilityEvaluationFailed::class]);

    app()->instance(DeterminesLinkadoEligibility::class, new class implements DeterminesLinkadoEligibility
    {
        public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
        {
            throw new RuntimeException('customer@example.test must never appear');
        }
    });
    p8Connection()->beginTransaction();

    $recorded = p8Recorder()->handle(
        'customer:sensitive-source-key',
        fn (string $eventId): EventData => p8Event(EventType::CustomerCreated, $eventId, 'sensitive-customer-id'),
    );

    expect($recorded)->toBeNull()
        ->and(OutboxEvent::query()->count())->toBe(0)
        ->and(p8Connection()->transactionLevel())->toBe(1);

    Event::assertDispatched(EligibilityEvaluationFailed::class, function (EligibilityEvaluationFailed $event): bool {
        $properties = get_object_vars($event);
        $encoded = json_encode($properties, JSON_THROW_ON_ERROR);

        expect(array_keys($properties))->toBe(['feature', 'eventType', 'exceptionClass'])
            ->and($event->feature)->toBe(LinkadoFeature::CustomerEvents)
            ->and($event->eventType)->toBe(EventType::CustomerCreated)
            ->and($event->exceptionClass)->toBe(RuntimeException::class)
            ->and($encoded)->not->toContain('customer@example.test')
            ->and($encoded)->not->toContain('sensitive-source-key')
            ->and($encoded)->not->toContain('sensitive-customer-id');

        return true;
    });
});

function p8Recorder(): RecordLinkadoEvent
{
    return app(RecordLinkadoEvent::class);
}

function p8Connection(): Connection
{
    return DB::connection('linkado_policy_test');
}

function p8OutboxMigration(): Migration
{
    return require __DIR__.'/../../../database/migrations/2026_01_01_000000_create_linkado_outbox_events_table.php';
}

function p8Event(EventType $type, string $eventId, string $customerId = 'customer-42'): EventData
{
    $common = [
        'event_id' => $eventId,
        'program_key' => 'program-key',
        'occurred_at' => '2026-09-21T10:11:12Z',
        'external_customer_id' => $customerId,
    ];

    return match ($type) {
        EventType::CustomerCreated => new CustomerCreatedEventData(...$common),
        EventType::LeadCreated => new LeadCreatedEventData(...$common),
        EventType::PaymentSucceeded => new PaymentSucceededEventData(
            ...$common,
            external_payment_id: 'payment-42',
            amount_minor: 1_000,
            currency: 'RUB',
            payment_kind: PaymentKind::OneTime,
        ),
        EventType::SubscriptionRenewed => new SubscriptionRenewedEventData(
            ...$common,
            external_payment_id: 'payment-42',
            external_subscription_id: 'subscription-42',
            amount_minor: 1_000,
            currency: 'RUB',
        ),
        EventType::SubscriptionCancelled => new SubscriptionCancelledEventData(
            ...$common,
            external_subscription_id: 'subscription-42',
        ),
        EventType::PaymentRefunded => new PaymentRefundedEventData(
            ...$common,
            external_payment_id: 'payment-42',
            external_refund_id: 'refund-42',
            refunded_amount_minor: 500,
            currency: 'RUB',
        ),
    };
}
