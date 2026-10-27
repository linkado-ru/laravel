<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Contracts\LocksLinkadoAttributionIdentity;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;
use Linkado\Laravel\Support\Attribution\AttributionManager;
use Linkado\Laravel\Tests\Support\Attribution\HostIdentityAdapter;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;
use Linkado\PhpSdk\Requests\ResolveTrackingClickRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    config()->set([
        'linkado.mode' => 'live', 'linkado.token' => 'synthetic-token', 'linkado.program_key' => 'test-program',
        'linkado.features.tracking' => true,
        'database.connections.last_visit' => DatabaseConfiguration::externalOrSqlite(), 'linkado.connection' => 'last_visit',
    ]);
    DB::purge('last_visit');
    $this->connection = DB::connection('last_visit');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_10_20_075845_add_visit_order_to_linkado_pending_attributions_table.php')->up();
    $this->travelTo('2026-10-20 10:00:00');
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    $this->connection->getSchemaBuilder()->dropIfExists('anonymous_identities');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->down();
    DB::purge('last_visit');
});

function lastVisitRequest(string $click = '01ARZ3NDEKTSV4RRFFQ69G5FAV', int $sequence = 1): Request
{
    return Request::create('/signup', cookies: [
        'linkado_visitor' => '01ARZ3NDEKTSV4RRFFQ69G5FAX',
        'lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAZ', // atomic snapshot must win over a torn legacy pair
        'lk_attribution' => json_encode([
            'version' => 1, 'program_key' => 'test-program', 'click_id' => $click,
            'referral_slug' => 'partner', 'visit_sequence' => $sequence,
            'expires_at' => '2099-01-01T00:00:00Z', // never trust the browser TTL
        ], JSON_THROW_ON_ERROR),
    ]);
}

/** @return array<string, mixed> */
function lastVisitFacts(string $click = '01ARZ3NDEKTSV4RRFFQ69G5FAV', int $order = 10): array
{
    return [
        'click_id' => $click, 'program_key' => 'test-program', 'referral_slug' => 'partner', 'visit_order' => $order,
        'created_at' => $order === 10 ? '2026-10-19T09:00:00+00:00' : '2026-10-20T09:00:00+00:00',
        'expires_at' => $order === 10 ? '2026-12-18T09:00:00+00:00' : '2026-12-19T09:00:00+00:00',
    ];
}

function captureLastVisit(Request $request): void
{
    app(CapturePendingAttribution::class)->handle($request, fn (): Response => new Response('host'));
}

it('freezes an existing click when its partner slug changed before the first capture', function (): void {
    // Arrange
    $request = lastVisitRequest();
    $mock = MockClient::global([ResolveTrackingClickRequest::class => MockResponse::make([
        'data' => [...lastVisitFacts(), 'referral_slug' => 'renamed-partner'],
    ])]);

    // Act
    captureLastVisit($request);
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($request));

    // Assert
    expect($snapshot?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($snapshot?->referralSlug)->toBeNull();
    $mock->assertSentCount(1);
});

it('replaces A with B and freezes B once despite a torn legacy cookie pair', function (): void {
    // Arrange
    $a = lastVisitRequest();
    $b = lastVisitRequest('01ARZ3NDEKTSV4RRFFQ69G5FAW', 2);
    $mock = MockClient::global([
        MockResponse::make(['data' => lastVisitFacts()]),
        MockResponse::make(['data' => lastVisitFacts('01ARZ3NDEKTSV4RRFFQ69G5FAW', 11)]),
    ]);
    captureLastVisit($a);
    captureLastVisit($b);

    // Act
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($b));
    $again = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($b));

    // Assert
    expect($snapshot?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAW')->and($again)->toBeNull();
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    expect($row->expires_at)->toBe('2026-12-19 09:00:00')->and($row->consumed_at)->not->toBeNull();
    $mock->assertSentCount(2);
});

it('ignores forged sequence and stale captures after a newer verified visit', function (): void {
    // Arrange
    $b = lastVisitRequest('01ARZ3NDEKTSV4RRFFQ69G5FAW', 1);
    $a = lastVisitRequest(sequence: PHP_INT_MAX);
    $mock = MockClient::global([
        MockResponse::make(['data' => lastVisitFacts('01ARZ3NDEKTSV4RRFFQ69G5FAW', 11)]),
        MockResponse::make(['data' => lastVisitFacts()]),
    ]);

    // Act
    captureLastVisit($b);
    captureLastVisit($a);
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($a));

    // Assert
    expect($snapshot?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAW');
    $mock->assertSentCount(2);
});

it('never replaces a verified candidate with a syntactically valid forged click', function (): void {
    // Arrange
    $mock = MockClient::global([MockResponse::make(['data' => lastVisitFacts()]), MockResponse::make(['data' => null])]);
    captureLastVisit(lastVisitRequest());

    // Act
    captureLastVisit(lastVisitRequest('01ARZ3NDEKTSV4RRFFQ69G5FAW', PHP_INT_MAX));

    // Assert
    expect($this->connection->table('linkado_pending_attributions')->sole()->click_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $mock->assertSentCount(2);
});

it('captures a prepared signup candidate and rolls it back with registration', function (): void {
    // Arrange
    $a = lastVisitRequest();
    $b = lastVisitRequest('01ARZ3NDEKTSV4RRFFQ69G5FAW', 2);
    $mock = MockClient::global([MockResponse::make(['data' => lastVisitFacts()]), MockResponse::make(['data' => lastVisitFacts('01ARZ3NDEKTSV4RRFFQ69G5FAW', 11)])]);
    captureLastVisit($a);
    app(AttributionManager::class)->prepare($b);

    // Act & Assert
    expect(fn () => $this->connection->transaction(function () use ($b): void {
        expect(app(AttributionManager::class)->consume($b)?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAW');

        throw new RuntimeException('Rollback signup.');
    }))->toThrow(RuntimeException::class, 'Rollback signup.');
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    expect($row->click_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')->and($row->consumed_at)->toBeNull();
    $mock->assertSentCount(2);
});

it('never verifies over HTTP inside a transaction even for an unseen signup request', function (): void {
    // Arrange
    $mock = MockClient::global([]);
    $request = lastVisitRequest();

    // Act
    $snapshot = $this->connection->transaction(function () use ($request) {
        app(AttributionManager::class)->prepare($request);

        return app(AttributionManager::class)->consume($request);
    });

    // Assert
    expect($snapshot)->toBeNull()->and($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
    $mock->assertNothingSent();
});

it('does not refresh an ordinary visit or the original server expiry on repeated cookie reads', function (): void {
    // Arrange
    $mock = MockClient::global([ResolveTrackingClickRequest::class => MockResponse::make(['data' => lastVisitFacts()])]);
    captureLastVisit(lastVisitRequest());
    $original = $this->connection->table('linkado_pending_attributions')->sole();
    $this->travelTo('2026-10-21 10:00:00');

    // Act
    captureLastVisit(lastVisitRequest(sequence: 90));

    // Assert
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($original);
    $mock->assertSentCount(2);
});

it('rejects invalid remote facts without changing a trusted pending row', function (string $case): void {
    // Arrange
    $facts = lastVisitFacts('01ARZ3NDEKTSV4RRFFQ69G5FAW', 11);
    $facts = match ($case) {
        'program' => [...$facts, 'program_key' => 'foreign-program'],
        'click' => [...$facts, 'click_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAZ'],
        'slug' => [...$facts, 'referral_slug' => 'Invalid Slug'],
        'order' => [...$facts, 'visit_order' => 0],
        'future' => [...$facts, 'created_at' => '2026-10-21T10:00:00Z'],
        'expiry' => [...$facts, 'expires_at' => '2026-10-20T10:00:00Z'],
        'malformed' => ['unexpected' => 'shape'],
        default => $facts,
    };
    $mock = MockClient::global([
        MockResponse::make(['data' => lastVisitFacts()]),
        MockResponse::make(['data' => $facts], $case === 'outage' ? 503 : 200),
    ]);
    captureLastVisit(lastVisitRequest());
    $original = $this->connection->table('linkado_pending_attributions')->sole();

    // Act
    captureLastVisit(lastVisitRequest('01ARZ3NDEKTSV4RRFFQ69G5FAW', PHP_INT_MAX));

    // Assert
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($original);
    $mock->assertSentCount(2);
})->with(['program', 'click', 'slug', 'order', 'future', 'expiry', 'malformed', 'outage']);

it('rejects malformed atomic cookies without falling back to a valid legacy click', function (): void {
    // Arrange
    $mock = MockClient::global([]);
    $request = lastVisitRequest();
    $request->cookies->set('lk_attribution', '{invalid');

    // Act
    captureLastVisit($request);

    // Assert
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
    $mock->assertNothingSent();
});

it('rejects expiry at the exact server boundary without taking the browser expiry', function (): void {
    // Arrange
    $mock = MockClient::global([ResolveTrackingClickRequest::class => MockResponse::make(['data' => lastVisitFacts()])]);
    $request = lastVisitRequest();
    captureLastVisit($request);
    $this->travelTo('2026-12-18 09:00:00');

    // Act
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($request));

    // Assert
    expect($snapshot)->toBeNull()->and($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
    $mock->assertSentCount(1);
});

it('keeps verified capture bound to the freshly locked anonymous identity', function (): void {
    // Arrange
    $this->connection->getSchemaBuilder()->create('anonymous_identities', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->boolean('claimed')->default(false);
    });
    $this->connection->table('anonymous_identities')->insert([['key' => 'a'], ['key' => 'b']]);
    app()->instance(LocksLinkadoAttributionIdentity::class,
        new HostIdentityAdapter($this->connection));
    $a = lastVisitRequest();
    $a->attributes->set('anonymous_identity', 'a');
    $b = lastVisitRequest('01ARZ3NDEKTSV4RRFFQ69G5FAW');
    $b->attributes->set('anonymous_identity', 'b');
    $mock = MockClient::global([MockResponse::make(['data' => lastVisitFacts()]), MockResponse::make(['data' => lastVisitFacts('01ARZ3NDEKTSV4RRFFQ69G5FAW', 11)])]);
    captureLastVisit($a);
    $original = $this->connection->table('linkado_pending_attributions')->sole();

    // Act
    captureLastVisit($b);
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($b));

    // Assert
    expect($snapshot)->toBeNull()->and($this->connection->table('linkado_pending_attributions')->sole())->toEqual($original);
    $mock->assertSentCount(2);
});

it('prevents late verified capture after signup even when pending data was pruned', function (): void {
    // Arrange
    $this->connection->getSchemaBuilder()->create('anonymous_identities', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->boolean('claimed')->default(false);
    });
    $this->connection->table('anonymous_identities')->insert(['key' => 'a']);
    app()->instance(LocksLinkadoAttributionIdentity::class,
        new HostIdentityAdapter($this->connection));
    $request = lastVisitRequest();
    $request->attributes->set('anonymous_identity', 'a');
    $mock = MockClient::global([ResolveTrackingClickRequest::class => MockResponse::make(['data' => lastVisitFacts()])]);
    app(AttributionManager::class)->prepare($request);
    $this->connection->transaction(function () use ($request): void {
        expect(app(AttributionManager::class)->consume($request)?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $this->connection->table('anonymous_identities')->update(['claimed' => true]);
    });
    $this->connection->table('linkado_pending_attributions')->delete();

    // Act
    captureLastVisit($request);

    // Assert
    expect($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
    $mock->assertSentCount(1);
});

it('does not consume a verified row after the configured program changes', function (): void {
    // Arrange
    $mock = MockClient::global([ResolveTrackingClickRequest::class => MockResponse::make(['data' => lastVisitFacts()])]);
    captureLastVisit(lastVisitRequest());
    config()->set('linkado.program_key', 'other-program');
    $request = lastVisitRequest();
    $data = json_decode($request->cookie('lk_attribution'), true, flags: JSON_THROW_ON_ERROR);
    $data['program_key'] = 'other-program';
    $request->cookies->set('lk_attribution', json_encode($data, JSON_THROW_ON_ERROR));

    // Act
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($request));

    // Assert
    expect($snapshot)->toBeNull();
    $mock->assertSentCount(1);
});

it('rejects an unverified stored click even when the browser repeats it', function (): void {
    // Arrange
    $this->connection->table('linkado_pending_attributions')->insert([
        'visitor_hash' => hash('sha256', '01ARZ3NDEKTSV4RRFFQ69G5FAX'),
        'click_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'captured_at' => now(), 'expires_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $mock = MockClient::global([]);

    // Act
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume(lastVisitRequest()));

    // Assert
    expect($snapshot)->toBeNull();
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    expect($row->visit_order)->toBeNull()->and($row->program_key)->toBeNull()
        ->and($row->click_id)->toBeNull()->and($row->consumed_at)->not->toBeNull();
    $mock->assertNothingSent();
});

it('does not capture a live prepared visit after switching out of live mode', function (string $mode): void {
    // Arrange
    $mock = MockClient::global([ResolveTrackingClickRequest::class => MockResponse::make(['data' => lastVisitFacts()])]);
    $request = lastVisitRequest();
    app(AttributionManager::class)->prepare($request);
    config()->set('linkado.mode', $mode);

    // Act
    captureLastVisit($request);
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($request));

    // Assert
    expect($snapshot)->toBeNull()
        ->and($this->connection->table('linkado_pending_attributions')->count())->toBe(0);
    $mock->assertSentCount(1);
})->with(['shadow', 'off']);

it('still consumes an already captured verified visit after leaving live mode', function (string $mode): void {
    // Arrange
    $mock = MockClient::global([ResolveTrackingClickRequest::class => MockResponse::make(['data' => lastVisitFacts()])]);
    $request = lastVisitRequest();
    captureLastVisit($request);
    config()->set('linkado.mode', $mode);

    // Act
    $snapshot = $this->connection->transaction(fn () => app(AttributionManager::class)->consume($request));

    // Assert
    expect($snapshot?->clickId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($this->connection->table('linkado_pending_attributions')->sole()->consumed_at)->not->toBeNull();
    $mock->assertSentCount(1);
})->with(['shadow', 'off']);
