<?php

declare(strict_types=1);

namespace Linkado\Laravel\Events;

use Carbon\CarbonImmutable;
use Linkado\Laravel\Enums\OutboxStatus;

final readonly class OutboxEventRecovered
{
    public function __construct(
        public string $eventId,
        public string $sourceKey,
        public OutboxStatus $status,
        public CarbonImmutable $recoveredAt,
    ) {}
}
