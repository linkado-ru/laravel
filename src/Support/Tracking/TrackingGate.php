<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Tracking;

use Closure;
use Illuminate\Http\Request;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Throwable;

/** @internal */
final readonly class TrackingGate
{
    /** @param Closure(): DeterminesLinkadoEligibility $eligibilityResolver */
    public function __construct(
        private LinkadoConfiguration $configuration,
        private Closure $eligibilityResolver,
    ) {}

    public function allows(Request $request): bool
    {
        try {
            if ($this->configuration->mode() === DeliveryMode::Off
                || ! $this->configuration->featureEnabled(LinkadoFeature::Tracking)) {
                return false;
            }

            $this->configuration->trackingClickCookie();
            $this->configuration->trackingReferralCookie();

            return ($this->eligibilityResolver)()->allows(
                LinkadoFeature::Tracking,
                new EligibilityContext(user: $request->user(), request: $request),
            );
        } catch (Throwable) {
            // Tracking is optional. Do not expose host policy failures or invent event diagnostics.
            return false;
        }
    }
}
