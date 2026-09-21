<?php

declare(strict_types=1);

namespace Linkado\Laravel\Events;

final readonly class OutboxPayloadConflictDetected
{
    public function __construct(
        public string $sourceKey,
        public string $existingEventId,
        public string $attemptedEventId,
    ) {}
}
