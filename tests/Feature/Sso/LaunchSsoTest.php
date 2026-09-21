<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Linkado\Laravel\Actions\CreateSsoLink;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\ResolvedSsoUser;
use Linkado\PhpSdk\Enums\SsoRedirect;
use Linkado\PhpSdk\LinkadoConnector;
use Linkado\PhpSdk\Requests\CreateSsoLinkRequest;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.sso', true);
    config()->set('linkado.program_key', 'program-public-key');
    config()->set('linkado.token', 'integration-credential');
    config()->set('linkado.base_url', 'https://linkado.test/api/v1');
    config()->set('linkado.sso.error_redirect', '/safe');

    p13BindResolver();
});

it('registers a named POST-only route with the configured protection middleware', function (): void {
    $route = Route::getRoutes()->getByName('linkado.sso.launch');

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toBe(['POST'])
        ->and($route?->gatherMiddleware())->toContain('web', 'auth', 'throttle:6,1');

    $this->get('/linkado/sso')->assertMethodNotAllowed();
});

it('rejects a POST without a CSRF token outside the unit-test environment', function (): void {
    app()->instance('env', 'production');

    $this->actingAs(p13User())
        ->post('/linkado/sso')
        ->assertStatus(419);
});

it('requires an authenticated user', function (): void {
    $this->postJson('/linkado/sso')->assertUnauthorized();
});

it('throttles the seventh launch request for the same user', function (): void {
    p13MockConnector(p13SsoResponse());
    $this->actingAs(p13User());

    foreach (range(1, 6) as $_) {
        $this->post('/linkado/sso')->assertRedirect('https://linkado.test/sso/signed-nonce');
    }

    $this->post('/linkado/sso')->assertTooManyRequests();
});

it('stops before resolving credentials or a user when mode feature or eligibility denies SSO', function (
    string $mode,
    bool $featureEnabled,
    bool $eligible,
): void {
    config()->set('linkado.mode', $mode);
    config()->set('linkado.features.sso', $featureEnabled);
    config()->set('linkado.token', null);
    config()->set('linkado.base_url', null);
    config()->set('linkado.program_key', null);

    app()->instance(DeterminesLinkadoEligibility::class, new readonly class($eligible) implements DeterminesLinkadoEligibility
    {
        public function __construct(private bool $eligible) {}

        public function allows(LinkadoFeature $feature, EligibilityContext $context): bool
        {
            return $this->eligible;
        }
    });

    $this->actingAs(p13User())
        ->post('/linkado/sso')
        ->assertRedirect('/safe')
        ->assertSessionHasErrors(['linkado' => 'Unable to open Linkado right now. Please try again.']);
})->with([
    'off mode' => ['off', true, true],
    'disabled feature' => ['live', false, true],
    'denied eligibility' => ['live', true, false],
]);

it('sends the exact resolved user payload outside a transaction and redirects without persisting the link', function (
    ?string $email,
    bool $verified,
): void {
    $resolver = p13BindResolver($email, $verified);
    $mockClient = p13MockConnector(p13SsoResponse());

    $response = $this->actingAs(p13User())->post('/linkado/sso');

    $response->assertRedirect('https://linkado.test/sso/signed-nonce')
        ->assertSessionMissing('linkado')
        ->assertSessionMissing('linkado_sso');

    expect($mockClient->getLastPendingRequest()?->body()->all())->toBe(array_filter([
        'program_key' => 'program-public-key',
        'external_user_id' => 'merchant-42',
        'email' => $email,
        'email_verified' => $verified,
        'display_name' => 'Partner Name',
        'redirect_to' => 'affiliate_portal',
    ], static fn (mixed $value): bool => $value !== null))
        ->and($resolver->transactionLevel)->toBe(0)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('signed-nonce');

    $mockClient->assertSentCount(1, CreateSsoLinkRequest::class);
})->with([
    'verified email' => ['partner@example.com', true],
    'nullable email' => [null, false],
]);

it('rejects malformed insecure and wrong-host redirect URLs', function (string $url): void {
    p13MockConnector(p13SsoResponse($url));

    $this->actingAs(p13User())
        ->post('/linkado/sso')
        ->assertRedirect('/safe')
        ->assertSessionHasErrors(['linkado' => 'Unable to open Linkado right now. Please try again.']);
})->with([
    'malformed' => ['not a URL'],
    'insecure' => ['http://linkado.test/sso/signed-nonce'],
    'wrong host' => ['https://evil.example/sso/signed-nonce'],
]);

it('rejects redirect authority changes and ambiguous URL syntax', function (string $url): void {
    p13MockConnector(p13SsoResponse($url));

    $this->actingAs(p13User())->post('/linkado/sso')
        ->assertRedirect('/safe')
        ->assertSessionHasErrors('linkado');
})->with([
    'username' => ['https://account@linkado.test/sso/link'],
    'password' => ['https://account:password@linkado.test/sso/link'],
    'empty user-info' => ['https://@linkado.test/sso/link'],
    'empty username' => ['https://:password@linkado.test/sso/link'],
    'foreign port' => ['https://linkado.test:8443/sso/link'],
    'host suffix' => ['https://linkado.test.evil.test/sso/link'],
    'trailing host dot' => ['https://linkado.test./sso/link'],
    'backslash in path' => ['https://linkado.test/sso/\\link'],
    'carriage return' => ["https://linkado.test/sso/\rlink"],
    'line feed' => ["https://linkado.test/sso/\nlink"],
    'tab' => ["https://linkado.test/sso/\tlink"],
    'encoded CRLF' => ['https://linkado.test/sso/%0d%0aLocation:evil'],
    'encoded control' => ['https://linkado.test/sso/%00link'],
]);

it('accepts only the configured effective HTTPS port', function (string $baseUrl, string $url, bool $allowed): void {
    config()->set('linkado.base_url', $baseUrl);
    p13MockConnector(p13SsoResponse($url));

    $response = $this->actingAs(p13User())->post('/linkado/sso');

    $response->assertRedirect($allowed ? $url : '/safe');

    if ($allowed) {
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasErrors('linkado');
    }
})->with([
    'implicit 443' => ['https://linkado.test/api/v1', 'https://linkado.test/sso/link', true],
    'explicit redirect 443' => ['https://linkado.test/api/v1', 'https://linkado.test:443/sso/link', true],
    'explicit base 443' => ['https://linkado.test:443/api/v1', 'https://linkado.test/sso/link', true],
    'case-insensitive host and scheme' => ['https://LINKADO.test/api/v1', 'HTTPS://linkado.TEST/sso/link', true],
    'matching custom port' => ['https://linkado.test:8443/api/v1', 'https://linkado.test:8443/sso/link', true],
    'missing custom port' => ['https://linkado.test:8443/api/v1', 'https://linkado.test/sso/link', false],
    'default instead of custom port' => ['https://linkado.test:8443/api/v1', 'https://linkado.test:443/sso/link', false],
    'different custom port' => ['https://linkado.test:8443/api/v1', 'https://linkado.test:9443/sso/link', false],
]);

it('turns Linkado HTTP failures into a safe translated redirect', function (int $status): void {
    p13MockConnector(MockResponse::make([
        'error' => [
            'code' => 'upstream_failure',
            'message' => 'The upstream request failed.',
        ],
    ], $status));

    $this->actingAs(p13User())
        ->post('/linkado/sso')
        ->assertRedirect('/safe')
        ->assertSessionHasErrors(['linkado' => 'Unable to open Linkado right now. Please try again.']);
})->with([401, 403, 422, 429, 500]);

it('turns network failures into a safe translated redirect', function (): void {
    $response = MockResponse::make()->throw(
        static fn (PendingRequest $request): FatalRequestException => new FatalRequestException(
            new RuntimeException('network detail that must stay private'),
            $request,
        ),
    );
    p13MockConnector($response);

    $this->actingAs(p13User())
        ->post('/linkado/sso')
        ->assertRedirect('/safe')
        ->assertSessionHasErrors(['linkado' => 'Unable to open Linkado right now. Please try again.']);
});

it('uses the Russian failure translation', function (): void {
    app()->setLocale('ru');
    p13MockConnector(MockResponse::make([], 500));

    $this->actingAs(p13User())
        ->post('/linkado/sso')
        ->assertRedirect('/safe')
        ->assertSessionHasErrors([
            'linkado' => 'Не удалось открыть Linkado. Пожалуйста, попробуйте ещё раз.',
        ]);
});

it('keeps credentials response bodies URLs and nonces out of logs and the session', function (): void {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    config()->set('linkado.token', 'token-that-must-not-leak');
    p13MockConnector(MockResponse::make([
        'token' => 'response-body-secret',
        'url' => 'https://linkado.test/sso/one-time-nonce',
    ], 500));

    $this->actingAs(p13User())
        ->post('/linkado/sso')
        ->assertRedirect('/safe');

    $logs = implode("\n", array_map(
        static fn (LogRecord $record): string => $record->message.' '.json_encode($record->context, JSON_THROW_ON_ERROR),
        $handler->getRecords(),
    ));
    $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);

    expect($logs)->toContain('Linkado SSO launch failed.')
        ->and($logs.$session)
        ->not->toContain(
            'token-that-must-not-leak',
            'response-body-secret',
            'https://linkado.test/sso/one-time-nonce',
            'one-time-nonce',
        );
});

it('redacts SSO failures at both the action and HTTP boundaries', function (string $failure): void {
    $nonce = Str::random(40);
    $token = Str::random(40);
    $email = Str::random(20).'@example.test';
    $url = 'https://linkado.test/sso/'.$nonce;
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    config()->set('linkado.token', $token);
    p13BindResolver($email);
    $networkCalls = 0;

    $response = match ($failure) {
        'invalid redirect' => p13SsoResponse('https://untrusted.test/sso/'.$nonce),
        'HTTP failure' => MockResponse::make(['error' => ['message' => $url], 'token' => $token], 500),
        'network failure' => MockResponse::make()->throw(
            static function (PendingRequest $request) use ($url, $token, &$networkCalls): FatalRequestException {
                $networkCalls++;

                return new FatalRequestException(new RuntimeException($url.' '.$token), $request);
            },
        ),
        'malformed DTO' => MockResponse::make([
            'data' => ['id' => 'synthetic-link', 'url' => $url, 'expires_at' => $nonce],
        ], 201),
    };
    $mockClient = p13MockConnector($response);
    $user = new GenericUser(['id' => $nonce, 'email' => $email, 'password' => $token]);
    $request = Request::create('/linkado/sso', 'POST', ['private_value' => $url]);
    $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    $exception = null;

    try {
        app(CreateSsoLink::class)->handle($user, $request);
    } catch (Throwable $caught) {
        $exception = $caught;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
    }

    expect($exception)->not->toBeNull();
    $details = $exception?->getMessage().' '.$exception?->getTraceAsString();

    foreach ([$nonce, $token, $email, $url] as $sensitive) {
        expect(str_contains($details, $sensitive))->toBeFalse();
    }

    expect($exception)->toBeInstanceOf(UnexpectedValueException::class)
        ->and($exception?->getPrevious())->toBeNull();

    $actionFrames = array_values(array_filter(
        $exception?->getTrace() ?? [],
        static fn (array $frame): bool => ($frame['class'] ?? null) === CreateSsoLink::class
            && $frame['function'] === 'handle',
    ));
    expect($actionFrames)->toHaveCount(1)
        ->and($actionFrames[0]['args'])->toHaveCount(2);

    foreach ($actionFrames[0]['args'] as $argument) {
        expect($argument)->toBeInstanceOf(SensitiveParameterValue::class);
    }

    $this->actingAs($user)->post('/linkado/sso')
        ->assertRedirect('/safe')
        ->assertSessionHasErrors('linkado');

    $logs = implode("\n", array_map(
        static fn (LogRecord $record): string => $record->message.' '.json_encode($record->context, JSON_THROW_ON_ERROR),
        $handler->getRecords(),
    ));
    $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);

    foreach ([$nonce, $token, $email, $url] as $sensitive) {
        expect(str_contains($logs.$session, $sensitive))->toBeFalse();
    }

    if ($failure === 'network failure') {
        // Saloon records responses, so connection failures need a transport attempt counter.
        expect($networkCalls)->toBe(2);
    } else {
        $mockClient->assertSentCount(2, CreateSsoLinkRequest::class);
    }
})->with(['invalid redirect', 'HTTP failure', 'network failure', 'malformed DTO']);

it('creates a fresh SSO link for each user without reusing the previous request payload', function (): void {
    app()->instance(ResolvesLinkadoSsoUser::class, new class implements ResolvesLinkadoSsoUser
    {
        public function resolve(Authenticatable $user): ResolvedSsoUser
        {
            return new ResolvedSsoUser(
                externalUserId: (string) $user->getAuthIdentifier(),
                email: null,
                emailVerified: false,
                displayName: 'Synthetic user',
                redirectTo: SsoRedirect::AffiliatePortal,
            );
        }
    });
    $firstUrl = 'https://linkado.test/sso/'.Str::random(40);
    $secondUrl = 'https://linkado.test/sso/'.Str::random(40);
    $mockClient = new MockClient([p13SsoResponse($firstUrl), p13SsoResponse($secondUrl)]);
    app(LinkadoConnector::class)->withMockClient($mockClient);
    $firstUser = p13User();
    $secondUser = p13User();

    $this->actingAs($firstUser)->post('/linkado/sso')->assertRedirect($firstUrl);
    expect($mockClient->getLastPendingRequest()?->body()->all()['external_user_id'])->toBe($firstUser->getAuthIdentifier());

    $this->actingAs($secondUser)->post('/linkado/sso')->assertRedirect($secondUrl);
    expect($mockClient->getLastPendingRequest()?->body()->all()['external_user_id'])->toBe($secondUser->getAuthIdentifier())
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($firstUrl, $secondUrl);

    $mockClient->assertSentCount(2, CreateSsoLinkRequest::class);
});

function p13User(): GenericUser
{
    return new GenericUser([
        'id' => 'host-user-'.Str::ulid(),
        'password' => '',
        'remember_token' => '',
    ]);
}

function p13BindResolver(?string $email = 'partner@example.com', bool $verified = true): object
{
    $resolver = new class($email, $verified) implements ResolvesLinkadoSsoUser
    {
        public int $transactionLevel = -1;

        public function __construct(
            private readonly ?string $email,
            private readonly bool $verified,
        ) {}

        public function resolve(Authenticatable $user): ResolvedSsoUser
        {
            $this->transactionLevel = DB::connection()->transactionLevel();

            return new ResolvedSsoUser(
                externalUserId: 'merchant-42',
                email: $this->email,
                emailVerified: $this->verified,
                displayName: 'Partner Name',
                redirectTo: SsoRedirect::AffiliatePortal,
            );
        }
    };

    app()->instance(ResolvesLinkadoSsoUser::class, $resolver);

    return $resolver;
}

function p13MockConnector(MockResponse $response): MockClient
{
    $mockClient = new MockClient([
        CreateSsoLinkRequest::class => $response,
    ]);
    $connector = new LinkadoConnector(
        token: (string) config('linkado.token'),
        baseUrl: (string) config('linkado.base_url'),
    );
    $connector->withMockClient($mockClient);
    app()->instance(LinkadoConnector::class, $connector);

    return $mockClient;
}

function p13SsoResponse(string $url = 'https://linkado.test/sso/signed-nonce'): MockResponse
{
    return MockResponse::make([
        'data' => [
            'id' => '01K5G8KPFYF3M6D4B7S1NZ8A2Q',
            'url' => $url,
            'expires_at' => '2026-09-21T12:05:00.000000Z',
        ],
    ], 201);
}

it('falls back to a local path when the configured error redirect is unsafe', function (string $url): void {
    config()->set('linkado.mode', 'off');
    config()->set('linkado.sso.error_redirect', $url);

    $this->actingAs(p13User())->post('/linkado/sso')->assertRedirect('/');
})->with(['https://external.test', '//external.test', '/\\external.test', "/safe\r\nLocation: https://external.test"]);
