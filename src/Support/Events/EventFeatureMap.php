<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Events;

use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\PhpSdk\Enums\EventType;

final class EventFeatureMap
{
    public function featureFor(EventType $type): LinkadoFeature
    {
        return match ($type) {
            EventType::CustomerCreated,
            EventType::LeadCreated => LinkadoFeature::CustomerEvents,
            EventType::PaymentSucceeded,
            EventType::SubscriptionRenewed,
            EventType::SubscriptionCancelled => LinkadoFeature::BillingEvents,
            EventType::PaymentRefunded => LinkadoFeature::RefundEvents,
        };
    }
}
