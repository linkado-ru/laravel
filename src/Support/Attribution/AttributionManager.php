<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Attribution;

use Illuminate\Http\Request;
use Linkado\Laravel\Actions\ConsumePendingAttribution;

final readonly class AttributionManager
{
    public function __construct(
        private ConsumePendingAttribution $consumePendingAttribution,
        private PreparedAttribution $preparedAttribution,
    ) {}

    public function prepare(Request $request): void
    {
        $this->preparedAttribution->prepare($request);
    }

    public function consume(Request $request): ?ConsumedAttribution
    {
        return $this->consumePendingAttribution->handle($request);
    }
}
