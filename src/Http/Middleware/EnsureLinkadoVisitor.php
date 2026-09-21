<?php

declare(strict_types=1);

namespace Linkado\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\Laravel\Support\Tracking\TrackingGate;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureLinkadoVisitor
{
    public function __construct(
        private LinkadoConfiguration $configuration,
        private TrackingGate $tracking,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = null;

        if ($this->tracking->allows($request)) {
            try {
                $cookie = $this->prepareCookie($request);
            } catch (InvalidLinkadoConfiguration|InvalidArgumentException) {
                // Resolve cookie configuration before mutating the request or running downstream.
            }
        }

        $response = $next($request);

        if ($cookie !== null) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    private function prepareCookie(Request $request): ?Cookie
    {
        $cookieName = $this->configuration->trackingVisitorCookie();
        $visitorId = $request->cookie($cookieName);

        if (is_string($visitorId) && Str::isUlid($visitorId)) {
            return null;
        }

        $visitorId = (string) Str::ulid();
        $cookie = Cookie::create(
            name: $cookieName,
            value: $visitorId,
            expire: now()->addSeconds($this->configuration->trackingTtlSeconds()),
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
        $request->cookies->set($cookieName, $visitorId);

        return $cookie;
    }
}
