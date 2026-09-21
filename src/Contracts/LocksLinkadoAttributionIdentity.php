<?php

declare(strict_types=1);

namespace Linkado\Laravel\Contracts;

use Closure;
use Illuminate\Http\Request;
use Linkado\Laravel\Support\Attribution\AttributionIdentity;

interface LocksLinkadoAttributionIdentity
{
    /**
     * Resolve a trusted server-side identity and re-read its lifecycle under FOR UPDATE
     * on the configured Linkado connection, inside the already active transaction.
     * Invoke the operation synchronously once while holding that lock, or not at all
     * if unresolved. Never commit the caller's transaction or perform remote requests.
     *
     * @param  Closure(AttributionIdentity): void  $operation
     */
    public function withLockedIdentity(Request $request, Closure $operation): void;
}
