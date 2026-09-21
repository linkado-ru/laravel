<?php

declare(strict_types=1);

namespace Linkado\Laravel\Events;

use Linkado\Laravel\Enums\OutboxStatus;

final readonly class OutboxEventRecorded
{
    public function __construct(
        public string $eventId,
        public string $sourceKey,
        public OutboxStatus $status,
    ) {}
}
