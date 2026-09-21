<?php

declare(strict_types=1);

namespace Linkado\Laravel\Tests\Support\Attribution;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Linkado\Laravel\Contracts\LocksLinkadoAttributionIdentity;
use Linkado\Laravel\Support\Attribution\AttributionIdentity;
use LogicException;

final readonly class HostIdentityAdapter implements LocksLinkadoAttributionIdentity
{
    public function __construct(private Connection $connection) {}

    public function withLockedIdentity(Request $request, Closure $operation): void
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new LogicException('The host identity requires the package transaction.');
        }
        $key = $request->attributes->get('anonymous_identity');

        if (! is_string($key)) {
            return;
        }
        $row = $this->connection->table('anonymous_identities')->where('key', $key)->lockForUpdate()->first();

        if ($row !== null) {
            $operation(new AttributionIdentity($row->key, ! $row->claimed));
        }
    }
}
