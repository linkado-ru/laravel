<?php

declare(strict_types=1);

namespace Linkado\Laravel\Events;

use Throwable;

final readonly class OutboxDispatchFailed
{
    /** @param class-string<Throwable> $exceptionClass */
    public function __construct(
        public string $eventId,
        public string $exceptionClass,
    ) {}
}
