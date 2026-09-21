<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Attribution;

final readonly class AttributionIdentity
{
    /** The stable, opaque server-side key must not contain PII. */
    public function __construct(
        public string $key,
        public bool $captureAllowed,
    ) {}
}
