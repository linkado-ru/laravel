<?php

declare(strict_types=1);

namespace Linkado\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Linkado\Laravel\Actions\RecordLinkadoEvent;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Exceptions\MissingSsoUserResolver;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Attribution\AttributionManager;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\ResolvedSsoUser;
use Linkado\PhpSdk\DataObjects\EventData;

class Linkado implements DeterminesLinkadoEligibility, ResolvesLinkadoSsoUser
{
    public function __construct(
        private readonly RecordLinkadoEvent $recordLinkadoEvent,
        private readonly AttributionManager $attributionManager,
    ) {}

    /** @param Closure(string): EventData $eventFactory */
    public function record(string $sourceKey, Closure $eventFactory): ?OutboxEvent
    {
        return $this->recordLinkadoEvent->handle($sourceKey, $eventFactory);
    }

    public function attribution(): AttributionManager
    {
        return $this->attributionManager;
    }

    public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
    {
        return true;
    }

    public function resolve(Authenticatable $user): ResolvedSsoUser
    {
        throw new MissingSsoUserResolver('Bind a Linkado SSO user resolver before enabling SSO.');
    }
}
