<?php

declare(strict_types=1);

namespace Linkado\Laravel\Enums;

enum LinkadoFeature: string
{
    case Sso = 'sso';
    case Tracking = 'tracking';
    case CustomerEvents = 'customer_events';
    case BillingEvents = 'billing_events';
    case RefundEvents = 'refund_events';
}
