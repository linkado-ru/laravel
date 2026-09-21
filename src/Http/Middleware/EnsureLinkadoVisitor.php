<?php

declare(strict_types=1);

namespace Linkado\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureLinkadoVisitor
{
    public function __construct(private LinkadoConfiguration $configuration) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->configuration->mode() === DeliveryMode::Off
            || ! $this->configuration->featureEnabled(LinkadoFeature::Tracking)) {
            return $next($request);
        }

        $cookieName = $this->configuration->trackingVisitorCookie();
        $visitorId = $request->cookie($cookieName);

        if (is_string($visitorId) && Str::isUlid($visitorId)) {
            return $next($request);
        }

        $visitorId = (string) Str::ulid();
        $request->cookies->set($cookieName, $visitorId);
        $response = $next($request);
        $response->headers->setCookie(Cookie::create(
            name: $cookieName,
            value: $visitorId,
            expire: now()->addSeconds($this->configuration->trackingTtlSeconds()),
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
