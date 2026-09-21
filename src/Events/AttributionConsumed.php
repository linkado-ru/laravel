<?php

declare(strict_types=1);

namespace Linkado\Laravel\Events;

use Carbon\CarbonImmutable;

final readonly class AttributionConsumed
{
    public function __construct(public CarbonImmutable $consumedAt) {}
}
