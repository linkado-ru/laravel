<?php

declare(strict_types=1);

namespace Linkado\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\CapturePendingAttribution as CapturePendingAttributionAction;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Symfony\Component\HttpFoundation\Response;

final readonly class CapturePendingAttribution
{
    public function __construct(
        private CapturePendingAttributionAction $capture,
        private LinkadoConfiguration $configuration,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->configuration->mode() === DeliveryMode::Off
            || ! $this->configuration->featureEnabled(LinkadoFeature::Tracking)) {
            return $next($request);
        }

        $visitorId = $request->cookie($this->configuration->trackingVisitorCookie());

        if (! is_string($visitorId) || ! Str::isUlid($visitorId)) {
            return $next($request);
        }

        $queryReferral = $request->query($this->configuration->trackingReferralParameter());
        $cookieReferral = $request->cookie($this->configuration->trackingReferralCookie());

        $this->capture->handle(
            visitorId: $visitorId,
            clickId: $request->cookie($this->configuration->trackingClickCookie()),
            referralSlug: $this->hasValue($queryReferral) ? $queryReferral : $cookieReferral,
        );

        return $next($request);
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
