<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Delivery;

use Carbon\CarbonImmutable;
use Linkado\PhpSdk\Enums\EventType;

final readonly class ClaimedOutboxEvent
{
    public function __construct(
        public string $eventId,
        public EventType $eventType,
        public string $payload,
        public string $payloadSha256,
        public int $attemptNumber,
        public string $claimToken,
        public CarbonImmutable $createdAt,
    ) {}
}
