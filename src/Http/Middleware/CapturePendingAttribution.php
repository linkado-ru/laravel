<?php

declare(strict_types=1);

namespace Linkado\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\CapturePendingAttribution as CapturePendingAttributionAction;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Support\Attribution\AttributionIdentifiers;
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

        $cookieReferral = $request->cookie($this->configuration->trackingReferralCookie());

        $this->capture->handleRequest(
            request: $request,
            visitorId: $visitorId,
            clickId: $request->cookie($this->configuration->trackingClickCookie()),
            referralSlug: AttributionIdentifiers::absent($cookieReferral) ? $this->queryReferral($request) : $cookieReferral,
        );
    }

    private function queryReferral(Request $request): mixed
    {
        $parameter = $this->configuration->trackingReferralParameter();
        $queryString = $request->server->get('QUERY_STRING');

        if (! is_string($queryString) || $queryString === '') {
            return $request->query($parameter);
        }

        // Global TrimStrings has already changed the query bag. Validate the original value.
        $invalid = false;
        set_error_handler(static function () use (&$invalid): bool {
            $invalid = true;

            return true;
        }, E_WARNING);

        try {
            $separators = (string) ini_get('arg_separator.input');
            $parts = $separators === '' ? [$queryString] : preg_split('/['.preg_quote($separators, '/').']/', $queryString);

            if ($parts === false) {
                return false;
            }

            foreach ($parts as $part) {
                $key = urldecode(explode('=', $part, 2)[0]);
                $bracket = strpos($key, '[');

                if ($bracket !== false) {
                    // PHP silently drops arrays beyond its nesting limit. Reject the selected
                    // array before that loss, retaining PHP's root-key normalization rules.
                    parse_str(rawurlencode(substr($key, 0, $bracket)).'=1', $root);

                    if (array_key_exists($parameter, $root)) {
                        return false;
                    }
                }
            }

            parse_str($queryString, $query);
        } finally {
            restore_error_handler();
        }

        // Parser warnings reject the candidate, never use a partial parse or trimmed fallback.
        return $invalid ? false : ($query[$parameter] ?? null);
    }
}
