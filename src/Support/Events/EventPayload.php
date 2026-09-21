<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Events;

use Linkado\PhpSdk\DataObjects\EventData;
use Linkado\PhpSdk\Enums\EventType;

final readonly class EventPayload
{
    /** @param class-string<EventData> $dtoClass */
    public function __construct(
        public string $dtoClass,
        public EventType $type,
        public string $payload,
        public string $sha256,
    ) {}
}
