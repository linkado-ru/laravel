<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Attribution;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Linkado\Laravel\Contracts\LocksLinkadoAttributionIdentity;
use Linkado\Laravel\Support\Database\RequiresActiveTransaction;
use LogicException;
use Throwable;

/** @internal */
final readonly class IdentityLock
{
    public function __construct(
        private Container $container,
        private RequiresActiveTransaction $transaction,
    ) {}

    /** @param Closure(?string): void $operation */
    public function run(?Request $request, Closure $operation): void
    {
        $connection = $this->transaction->ensure();

        // A savepoint also undoes the operation when a faulty adapter throws AFTER
        // invoking it, even if the host catches that error inside its transaction.
        $connection->transaction(function () use ($request, $operation): void {
            if (! $this->container->bound(LocksLinkadoAttributionIdentity::class)) {
                $operation(null);

                return;
            }

            if ($request === null) {
                return;
            }

            $adapter = $this->container->make(LocksLinkadoAttributionIdentity::class);
            $state = new class
            {
                public bool $called = false;

                public bool $active = true;

                public ?Throwable $failure = null;
            };

            try {
                $adapter->withLockedIdentity($request, function (mixed ...$arguments) use ($operation, $state): void {
                    if (! $state->active || $state->called) {
                        throw $state->failure = new LogicException('The attribution identity callback must run synchronously at most once.');
                    }
                    $state->called = true;

                    try {
                        // Validate inside the guard so swallowed arity/type errors also
                        // invalidate the whole operation instead of leaving partial writes.
                        if (count($arguments) !== 1) {
                            throw new LogicException('The attribution identity callback requires exactly one argument.');
                        }

                        $hash = $this->identityHash($arguments[0] ?? $arguments['identity'] ?? null);

                        if ($hash !== null) {
                            $operation($hash);
                        }
                    } catch (Throwable $exception) {
                        $state->failure = $exception;

                        throw $exception;
                    }
                });
            } finally {
                $state->active = false;
            }

            // An adapter must not convert a failed operation into a partial success.
            if ($state->failure !== null) {
                throw $state->failure;
            }
        });
    }

    private function identityHash(mixed $identity): ?string
    {
        if (! $identity instanceof AttributionIdentity || $identity->key === '') {
            throw new LogicException('The attribution identity callback requires an identity with a nonempty key.');
        }

        return $identity->captureAllowed ? hash('sha256', 'linkado:attribution-identity:'.$identity->key) : null;
    }
}
