<?php

declare(strict_types=1);

namespace Linkado\Laravel\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Shadow = 'shadow';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
