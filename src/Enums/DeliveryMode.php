<?php

declare(strict_types=1);

namespace Linkado\Laravel\Enums;

enum DeliveryMode: string
{
    case Off = 'off';
    case Shadow = 'shadow';
    case Live = 'live';
}
