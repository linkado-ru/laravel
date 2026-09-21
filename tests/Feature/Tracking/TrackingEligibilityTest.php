<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Events\EligibilityEvaluationFailed;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;
use Linkado\Laravel\Http\Middleware\EnsureLinkadoVisitor;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\Tracking\TrackingRenderer;
use Linkado\PhpSdk\LinkadoConnector;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('database.connections.tracking_policy', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    config()->set('linkado.connection', 'tracking_policy');
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.tracking', true);
    config()->set('linkado.program_key', 'public-program');
    config()->set('linkado.tracking.script_url', 'https://cdn.example.test/tracking.js');
    config()->set('linkado.tracking.endpoint_url', 'https://api.example.test/track');
    DB::purge('tracking_policy');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->up();

    Route::middleware(['web', 'linkado.attribution'])->get('/_tracking-policy', fn () => response(
        'host-content'.Blade::render('@linkadoTracking', deleteCachedView: true),
    ));
});

afterEach(function (): void {
    DB::purge('tracking_policy');
});

function trackingPolicy(Closure $policy): void
{
    app()->bind(DeterminesLinkadoEligibility::class, fn () => new readonly class($policy) implements DeterminesLinkadoEligibility
    {
        public function __construct(private Closure $policy) {}

        public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
        {
            return ($this->policy)($feature, $context);
        }
    });
}

it('does not capture an existing visitor when host tracking policy denies', function (): void {
    trackingPolicy(fn (): bool => false);

    $this->withCookie('linkado_visitor', (string) Str::ulid())
        ->withUnencryptedCookie('lk_click', 'click-denied')
        ->get('/_tracking-policy')->assertOk()->assertSee('host-content')->assertDontSee('<script', false);

    expect(DB::connection('tracking_policy')->table('linkado_pending_attributions')->count())->toBe(0);
});

it('fails closed across the HTTP tracking path without resolving SDK or emitting event diagnostics', function (string $failure): void {
    Event::fake([EligibilityEvaluationFailed::class]);
    $sdkResolutions = 0;
    app()->bind(LinkadoConnector::class, function () use (&$sdkResolutions): never {
        $sdkResolutions++;

        throw new RuntimeException('SDK must remain lazy');
    });
    $resolverCalls = 0;
    app()->bind(DeterminesLinkadoEligibility::class, function () use (&$resolverCalls, $failure): DeterminesLinkadoEligibility {
        $resolverCalls++;

        if ($failure === 'construction') {
            throw new RuntimeException('synthetic-private-context');
        }

        return new readonly class($failure) implements DeterminesLinkadoEligibility
        {
            public function __construct(private string $failure) {}

            public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
            {
                if ($this->failure === 'exception') {
                    throw new RuntimeException('synthetic-private-context');
                }

                if ($this->failure === 'error') {
                    throw new TypeError('synthetic-private-context');
                }

                return $this->failure !== 'denied';
            }
        };
    });
    match ($failure) {
        'off' => config()->set('linkado.mode', 'off'),
        'disabled' => config()->set('linkado.features.tracking', false),
        'mode' => config()->set('linkado.mode', 'invalid'),
        'feature' => config()->set('linkado.features.tracking', 'invalid'),
        'ttl' => config()->set('linkado.tracking.ttl_seconds', 0),
        default => null,
    };

    $this->get('/_tracking-policy?ref=partner')->assertOk()
        ->assertContent('host-content')->assertCookieMissing('linkado_visitor');

    expect(DB::connection('tracking_policy')->table('linkado_pending_attributions')->count())->toBe(0)
        ->and($sdkResolutions)->toBe(0);

    if (in_array($failure, ['off', 'disabled', 'mode', 'feature'], true)) {
        expect($resolverCalls)->toBe(0);
    }
    Event::assertNotDispatched(EligibilityEvaluationFailed::class);
})->with(['denied', 'exception', 'error', 'construction', 'off', 'disabled', 'mode', 'feature', 'ttl']);

it('passes fresh request user and no event to each tracking surface', function (string $surface): void {
    $seen = [];
    trackingPolicy(function (LinkadoFeature $feature, EligibilityContext $context) use (&$seen): bool {
        $seen[] = [$feature, $context->request, $context->user, $context->event];

        return $context->user?->getAuthIdentifier() === 'allowed'
            && ! $context->request?->attributes->get('impersonating');
    });
    $service = app(match ($surface) {
        'capture' => CapturePendingAttribution::class,
        'visitor' => EnsureLinkadoVisitor::class,
        default => TrackingRenderer::class,
    });

    foreach (['allowed', 'authenticated', 'admin', 'impersonating'] as $index => $identity) {
        $user = new GenericUser(['id' => $identity === 'impersonating' ? 'allowed' : $identity]);
        $request = Request::create('/?ref=partner');
        $request->attributes->set('impersonating', $identity === 'impersonating');

        if ($surface === 'capture') {
            $request->cookies->set('linkado_visitor', (string) Str::ulid());
        }
        app()->instance('request', $request);
        $request->setUserResolver(fn () => $user);
        $allowed = $index === 0;

        if ($service instanceof TrackingRenderer) {
            expect(str_contains($service->render(), '<script'))->toBe($allowed);
        } else {
            $response = $service->handle($request, fn (): Response => new Response('host-content'));
            expect($response->getContent())->toBe('host-content');

            if ($surface === 'visitor') {
                expect(count($response->headers->getCookies()))->toBe($allowed ? 1 : 0)
                    ->and($request->cookies->has('linkado_visitor'))->toBe($allowed);
            } else {
                expect(DB::connection('tracking_policy')->table('linkado_pending_attributions')->count())->toBe(1);
            }
        }
        expect($seen[$index] ?? null)->toBe([LinkadoFeature::Tracking, $request, $user, null]);
    }
    expect($seen)->toHaveCount(4);
})->with(['capture', 'visitor', 'renderer']);

it('propagates downstream exceptions exactly once outside tracking failure handling', function (string $middleware, bool $deny): void {
    trackingPolicy(fn (): bool => ! $deny);
    $request = Request::create('/');
    $calls = 0;
    $failure = new RuntimeException('downstream failure');

    try {
        app($middleware)->handle($request, function () use (&$calls, $failure): never {
            $calls++;

            throw $failure;
        });
        test()->fail('The downstream exception must propagate.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect($calls)->toBe(1);
})->with([CapturePendingAttribution::class, EnsureLinkadoVisitor::class])->with([false, true]);

it('contains current user resolution failures on every tracking surface', function (): void {
    $request = Request::create('/?ref=partner');
    app()->instance('request', $request);
    $request->setUserResolver(fn (): never => throw new RuntimeException('user resolution failed'));

    expect(app(TrackingRenderer::class)->render())->toBe('');
    foreach ([EnsureLinkadoVisitor::class, CapturePendingAttribution::class] as $middleware) {
        $response = app($middleware)->handle($request, fn (): Response => new Response('host-content'));
        expect($response->getContent())->toBe('host-content')
            ->and($response->headers->getCookies())->toBe([]);
    }
    expect(DB::connection('tracking_policy')->table('linkado_pending_attributions')->count())->toBe(0);
});

it('boots default bindings and permits tracking without credentials', function (): void {
    config()->set('linkado.token', null);
    $this->get('/_tracking-policy?ref=partner')->assertOk()->assertSee('<script', false)
        ->assertCookie('linkado_visitor');
    expect(DB::connection('tracking_policy')->table('linkado_pending_attributions')->count())->toBe(1);
});

it('keeps storage failures visible instead of treating them as optional tracking setup', function (): void {
    DB::connection('tracking_policy')->getSchemaBuilder()->drop('linkado_pending_attributions');
    $request = Request::create('/?ref=partner', cookies: ['linkado_visitor' => (string) Str::ulid()]);
    $calls = 0;
    expect(fn () => app(CapturePendingAttribution::class)->handle($request, function () use (&$calls): Response {
        $calls++;

        return new Response('host-content');
    }))->toThrow(QueryException::class);
    expect($calls)->toBe(0);
});

it('does not issue or inject a visitor when cookie configuration is unusable', function (mixed $name): void {
    config()->set('linkado.tracking.visitor_cookie', $name);
    $request = Request::create('/');
    $calls = 0;
    $response = app(EnsureLinkadoVisitor::class)->handle($request, function () use (&$calls): Response {
        $calls++;

        return new Response('host-content');
    });
    expect($calls)->toBe(1)
        ->and($response->getContent())->toBe('host-content')
        ->and($response->headers->getCookies())->toBe([])
        ->and($request->cookies->all())->toBe([]);
})->with([[null], [''], [[]], ['0']]);

it('contains capture configuration failures for an existing visitor without changing rows', function (string $key): void {
    config()->set('linkado.'.$key, null);
    $request = Request::create('/?ref=partner', cookies: ['linkado_visitor' => (string) Str::ulid()]);
    $calls = 0;
    $response = app(CapturePendingAttribution::class)->handle($request, function () use (&$calls): Response {
        $calls++;

        return new Response('host-content');
    });
    expect($calls)->toBe(1)
        ->and($response->getContent())->toBe('host-content')
        ->and(DB::connection('tracking_policy')->table('linkado_pending_attributions')->count())->toBe(0);
})->with(['tracking.visitor_cookie', 'tracking.click_cookie', 'tracking.referral_cookie', 'tracking.referral_parameter', 'tracking.ttl_seconds']);
