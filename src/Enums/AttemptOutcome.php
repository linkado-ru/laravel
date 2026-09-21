<?php

declare(strict_types=1);

namespace Linkado\Laravel\Enums;

enum AttemptOutcome: string
{
    case Delivered = 'delivered';
    case RetryScheduled = 'retry_scheduled';
    case Failed = 'failed';
    case PackageDisabled = 'package_disabled';
    case ManuallyRetried = 'manually_retried';
}
