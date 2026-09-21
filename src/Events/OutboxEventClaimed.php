<?php

declare(strict_types=1);

namespace Linkado\Laravel\Events;

use Carbon\CarbonImmutable;
use Linkado\Laravel\Enums\OutboxStatus;

final readonly class OutboxEventClaimed
{
    public function __construct(
        public string $eventId,
        public string $sourceKey,
        public OutboxStatus $status,
        public int $attemptNumber,
        public CarbonImmutable $claimedAt,
    ) {}
}
