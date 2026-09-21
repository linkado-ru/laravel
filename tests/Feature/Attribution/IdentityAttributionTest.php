<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Linkado\Laravel\Actions\CapturePendingAttribution as CaptureAction;
use Linkado\Laravel\Contracts\LocksLinkadoAttributionIdentity;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;
use Linkado\Laravel\Support\Attribution\AttributionIdentity;
use Linkado\Laravel\Support\Attribution\AttributionManager;
use Linkado\Laravel\Tests\Support\Attribution\HostIdentityAdapter;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('database.connections.identity_test', DatabaseConfiguration::externalOrSqlite());
    config()->set('linkado.connection', 'identity_test');
    config()->set('linkado.mode', 'shadow');
    config()->set('linkado.features.tracking', true);
    DB::purge('identity_test');
    $this->connection = DB::connection('identity_test');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php')->up();
    $this->connection->getSchemaBuilder()->create('anonymous_identities', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->boolean('claimed')->default(false);
    });
    $this->connection->table('anonymous_identities')->insert(['key' => 'anonymous-a', 'claimed' => false]);
});

afterEach(function (): void {
    $this->connection->getSchemaBuilder()->dropIfExists('anonymous_identities');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->down();
    DB::purge('identity_test');
});

it('rejects a late capture after the previously read anonymous identity was claimed', function (): void {
    $stale = $this->connection->table('anonymous_identities')->where('key', 'anonymous-a')->sole();
    $request = Request::create('/landing', 'GET', cookies: ['linkado_visitor' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'lk_referral' => 'first']);
    $request->attributes->set('anonymous_identity', $stale->key);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    $this->connection->table('anonymous_identities')->update(['claimed' => true]);

    app(CapturePendingAttribution::class)->handle($request, fn (): Response => new Response('host'));

    expect($stale->claimed)->toBeIn([false, 0])
        ->and($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
});

function identityRequest(?string $key = 'anonymous-a', ?string $visitor = '01ARZ3NDEKTSV4RRFFQ69G5FAV', ?string $slug = 'first'): Request
{
    $request = Request::create('/landing', 'GET', cookies: ['linkado_visitor' => $visitor, 'lk_referral' => $slug]);
    $request->attributes->set('anonymous_identity', $key);

    return $request;
}

function identityCapture(Request $request): void
{
    app(CapturePendingAttribution::class)->handle($request, fn (): Response => new Response('host'));
}

it('binds the first touch to a namespaced hash and consumes it once after commit', function (): void {
    Event::fake([AttributionConsumed::class]);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    expect($row->identity_hash)->toBe(hash('sha256', 'linkado:attribution-identity:anonymous-a'))
        ->and($row->identity_hash)->not->toBe(hash('sha256', 'anonymous-a'));
    $this->connection->transaction(function (): void {
        $snapshot = app(AttributionManager::class)->consume(identityRequest());
        expect($snapshot?->referralSlug)->toBe('first')
            ->and(app(AttributionManager::class)->consume(identityRequest()))->toBeNull();
        Event::assertNotDispatched(AttributionConsumed::class);
    });
    Event::assertDispatchedTimes(AttributionConsumed::class, 1);
    $marker = $this->connection->table('linkado_pending_attributions')->sole();
    expect($marker->identity_hash)->toBe($row->identity_hash)
        ->and($marker->expires_at)->toBe($row->expires_at)
        ->and($marker->referral_slug)->toBeNull();
});

it('never modifies another identity row even with invalid cookies or expiry', function (bool $expired, ?string $slug): void {
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $this->connection->table('anonymous_identities')->insert(['key' => 'anonymous-b']);

    if ($expired) {
        $this->connection->table('linkado_pending_attributions')->update(['expires_at' => now()->subSecond()]);
    }
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    $this->connection->transaction(function () use ($slug): void {
        expect(app(AttributionManager::class)->consume(identityRequest('anonymous-b', slug: $slug)))->toBeNull();
    });
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($row);

    // Capture may start a fresh window only after expiry; active ownership is immutable.
    if (! $expired) {
        $request = identityRequest('anonymous-b');
        $request->cookies->set('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAW');
        identityCapture($request);
        expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($row);
    }
})->with([false, true])->with([null, 'wrong', 'first']);

it('does not adopt active legacy rows or expose bound rows after adapter removal', function (): void {
    app(CaptureAction::class)->handle('01ARZ3NDEKTSV4RRFFQ69G5FAV', null, 'first');
    $legacy = $this->connection->table('linkado_pending_attributions')->sole();
    $manager = app(AttributionManager::class);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $this->connection->transaction(function () use ($manager): void {
        expect($manager->consume(identityRequest()))->toBeNull();
    });
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($legacy);
    $this->connection->table('linkado_pending_attributions')->update(['expires_at' => now()->subSecond()]);
    identityCapture(identityRequest());
    $bound = $this->connection->table('linkado_pending_attributions')->sole();
    expect($bound->identity_hash)->not->toBeNull();
    app()->offsetUnset(LocksLinkadoAttributionIdentity::class);
    $this->connection->transaction(function () use ($manager): void {
        expect($manager->consume(identityRequest()))->toBeNull();
    });
    app(CaptureAction::class)->handle('01ARZ3NDEKTSV4RRFFQ69G5FAV', '01ARZ3NDEKTSV4RRFFQ69G5FAW', null);
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($bound);
});

it('fails closed for unresolved or closed identities without sharing request state', function (?string $key): void {
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    $middleware = app(CapturePendingAttribution::class);
    $manager = app(AttributionManager::class);
    identityCapture(identityRequest());
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    $this->connection->table('anonymous_identities')->insert(['key' => 'closed', 'claimed' => true]);
    $request = identityRequest($key);
    $middleware->handle($request, fn (): Response => new Response('host'));
    $this->connection->transaction(function () use ($manager, $request): void {
        expect($manager->consume($request))->toBeNull();
    });
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($row);
    $this->connection->transaction(function () use ($manager): void {
        expect($manager->consume(identityRequest())?->referralSlug)->toBe('first');
    });
})->with([null, 'absent', 'closed']);

it('blocks closed identity capture after expiry prune and visitor rotation', function (string $transition): void {
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $this->connection->table('anonymous_identities')->update(['claimed' => true]);
    $this->connection->table('linkado_pending_attributions')->update(['expires_at' => now()->subSecond()]);

    if ($transition === 'prune') {
        $this->artisan('linkado:prune')->assertSuccessful();
    }
    $before = $this->connection->table('linkado_pending_attributions')->get();
    identityCapture(identityRequest(visitor: $transition === 'rotate' ? '01ARZ3NDEKTSV4RRFFQ69G5FAW' : '01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    expect($this->connection->table('linkado_pending_attributions')->get())->toEqual($before);
})->with(['expiry', 'prune', 'rotate']);

it('cannot bypass a bound adapter using the legacy requestless capture action', function (): void {
    $action = app(CaptureAction::class);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    $action->handle('01ARZ3NDEKTSV4RRFFQ69G5FAV', null, 'first');
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
    app()->offsetUnset(LocksLinkadoAttributionIdentity::class);
    $action->handle('01ARZ3NDEKTSV4RRFFQ69G5FAV', null, 'first');
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(1);
});

it('locks identity before reading pending even without visitor source or enabled events', function (?string $visitor, ?string $slug): void {
    config()->set('linkado.mode', 'off');
    config()->set('linkado.features.customer_events', false);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    $tables = [];
    $this->connection->beforeExecuting(function (string $sql) use (&$tables): void {
        if (str_starts_with($sql, 'select')) {
            $tables[] = str_contains($sql, 'anonymous_identities') ? 'identity' : 'pending';
        }
    });
    $this->connection->transaction(function () use ($visitor, $slug): void {
        expect(app(AttributionManager::class)->consume(identityRequest(visitor: $visitor, slug: $slug)))->toBeNull();
        $this->connection->table('anonymous_identities')->update(['claimed' => true]);
    });
    expect($tables[0])->toBe('identity')
        ->and($this->connection->table('anonymous_identities')->value('claimed'))->toBeIn([true, 1]);
})->with([null, '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->with([null, 'first']);

it('rolls back claim and consumption together allowing legitimate capture again', function (): void {
    Event::fake([AttributionConsumed::class]);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $row = $this->connection->table('linkado_pending_attributions')->sole();

    try {
        $this->connection->transaction(function (): void {
            expect(app(AttributionManager::class)->consume(identityRequest())?->referralSlug)->toBe('first');
            $this->connection->table('anonymous_identities')->update(['claimed' => true]);

            throw new RuntimeException('Rollback claim');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(AttributionConsumed::class);
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($row);
    identityCapture(identityRequest(visitor: '01ARZ3NDEKTSV4RRFFQ69G5FAW'));
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(2);
});

it('rolls back operation writes if the adapter throws or repeats the callback', function (string $failure, string $operation): void {
    Event::fake([AttributionConsumed::class]);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    app()->instance(LocksLinkadoAttributionIdentity::class, new readonly class($failure) implements LocksLinkadoAttributionIdentity
    {
        public function __construct(private string $failure) {}

        public function withLockedIdentity(Request $request, Closure $operation): void
        {
            $operation(new AttributionIdentity('anonymous-a', true));

            if ($this->failure === 'throw') {
                throw new RuntimeException('Adapter failure');
            }

            try {
                $operation(new AttributionIdentity('anonymous-a', true));
            } catch (LogicException $exception) {
                if ($this->failure !== 'swallow-repeat') {
                    throw $exception;
                }
            }
        }
    });
    $this->connection->transaction(function () use ($operation, $failure): void {
        expect(function () use ($operation): void {
            if ($operation === 'consume') {
                app(AttributionManager::class)->consume(identityRequest());
            } else {
                $request = identityRequest();
                $request->cookies->set('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAW');
                identityCapture($request);
            }
        })->toThrow($failure === 'throw' ? RuntimeException::class : LogicException::class);
    });
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($row);
    Event::assertNotDispatched(AttributionConsumed::class);
})->with(['throw', 'repeat', 'swallow-repeat'])->with(['capture', 'consume']);

it('rejects an adapter that swallows an invalid callback argument before a valid callback', function (): void {
    app()->instance(LocksLinkadoAttributionIdentity::class, new class implements LocksLinkadoAttributionIdentity
    {
        public function withLockedIdentity(Request $request, Closure $operation): void
        {
            try {
                $operation(null);
            } catch (Throwable) {
            }
            $operation(new AttributionIdentity('anonymous-a', true));
        }
    });
    expect(fn () => identityCapture(identityRequest()))->toThrow(LogicException::class);
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
});

it('rejects deferred callbacks without writing outside the identity transaction', function (): void {
    $adapter = new class implements LocksLinkadoAttributionIdentity
    {
        public ?Closure $deferred = null;

        public function withLockedIdentity(Request $request, Closure $operation): void
        {
            $this->deferred = $operation;
        }
    };
    app()->instance(LocksLinkadoAttributionIdentity::class, $adapter);
    identityCapture(identityRequest());
    expect(fn () => ($adapter->deferred)(new AttributionIdentity('anonymous-a', true)))->toThrow(LogicException::class);
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(0)
        ->and($this->connection->transactionLevel())->toBe(0);
});

it('preserves ownership and the first window during same identity slug upgrades', function (): void {
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $original = $this->connection->table('linkado_pending_attributions')->sole();
    $request = identityRequest();
    $request->cookies->set('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAW');
    identityCapture($request);
    $upgraded = $this->connection->table('linkado_pending_attributions')->sole();
    expect($upgraded->identity_hash)->toBe($original->identity_hash)
        ->and($upgraded->captured_at)->toBe($original->captured_at)
        ->and($upgraded->expires_at)->toBe($original->expires_at)
        ->and($upgraded->click_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAW')
        ->and($upgraded->referral_slug)->toBeNull();
});

it('rolls back callbacks with missing or extra arguments even when the adapter suppresses errors', function (string $arity, string $sequence, string $operation): void {
    Event::fake([AttributionConsumed::class]);
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    identityCapture(identityRequest());
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    app()->instance(LocksLinkadoAttributionIdentity::class, new readonly class($arity, $sequence) implements LocksLinkadoAttributionIdentity
    {
        public function __construct(private string $arity, private string $sequence) {}

        public function withLockedIdentity(Request $request, Closure $operation): void
        {
            $identity = new AttributionIdentity('anonymous-a', true);

            if ($this->sequence === 'after') {
                $operation($identity);
            }

            try {
                if ($this->arity === 'missing') {
                    $operation();
                } elseif ($this->arity === 'named') {
                    $operation(unexpected: $identity);
                } else {
                    $operation($identity, 'unexpected');
                }
            } catch (Throwable) {
            }

            if ($this->sequence === 'before') {
                try {
                    $operation($identity);
                } catch (Throwable) {
                }
            }
        }
    });
    $this->connection->transaction(function () use ($operation): void {
        expect(function () use ($operation): void {
            if ($operation === 'consume') {
                app(AttributionManager::class)->consume(identityRequest());
            } else {
                $request = identityRequest();
                $request->cookies->set('lk_click', '01ARZ3NDEKTSV4RRFFQ69G5FAW');
                identityCapture($request);
            }
        })->toThrow(LogicException::class);
    });
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($row);
    Event::assertNotDispatched(AttributionConsumed::class);
})->with(['missing', 'extra', 'named'])->with(['before', 'after', 'only'])->with(['capture', 'consume']);
