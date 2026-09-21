<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Attribution;

final readonly class ConsumedAttribution
{
    public function __construct(
        public ?string $clickId,
        public ?string $referralSlug,
    ) {}
}
