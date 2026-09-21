<?php

declare(strict_types=1);

namespace Linkado\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\CapturePendingAttribution as CapturePendingAttributionAction;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\Laravel\Support\Tracking\TrackingGate;
use Symfony\Component\HttpFoundation\Response;

final readonly class CapturePendingAttribution
{
    public function __construct(
        private CapturePendingAttributionAction $capture,
        private LinkadoConfiguration $configuration,
        private TrackingGate $tracking,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tracking->allows($request)) {
            try {
                $this->captureFromRequest($request);
            } catch (InvalidLinkadoConfiguration) {
                // Invalid optional tracking configuration must not break the host request.
            }
        }

        return $next($request);
    }

    private function captureFromRequest(Request $request): void
    {
        $visitorId = $request->cookie($this->configuration->trackingVisitorCookie());

        if (! is_string($visitorId) || ! Str::isUlid($visitorId)) {
            return;
        }

        $queryReferral = $request->query($this->configuration->trackingReferralParameter());
        $cookieReferral = $request->cookie($this->configuration->trackingReferralCookie());

        $this->capture->handle(
            visitorId: $visitorId,
            clickId: $request->cookie($this->configuration->trackingClickCookie()),
            referralSlug: $this->hasValue($queryReferral) ? $queryReferral : $cookieReferral,
        );
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
