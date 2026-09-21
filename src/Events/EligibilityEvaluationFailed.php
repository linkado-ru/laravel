<?php

declare(strict_types=1);

namespace Linkado\Laravel\Events;

use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\PhpSdk\Enums\EventType;
use Throwable;

final readonly class EligibilityEvaluationFailed
{
    /** @param class-string<Throwable> $exceptionClass */
    public function __construct(
        public LinkadoFeature $feature,
        public EventType $eventType,
        public string $exceptionClass,
    ) {}
}
