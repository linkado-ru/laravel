<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Actions\CapturePendingAttribution;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Support\Attribution\ConsumedAttribution;
use Linkado\Laravel\Tests\Support\DatabaseConfiguration;

beforeEach(function (): void {
    config()->set('database.connections.matching', DatabaseConfiguration::externalOrSqlite());
    config()->set('linkado.connection', 'matching');
    config()->set('linkado.tracking.ttl_seconds', 120);
    DB::purge('matching');
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->up();
    (require __DIR__.'/../../../database/migrations/2026_09_21_000003_add_identity_hash_to_linkado_pending_attributions_table.php')->up();
    CarbonImmutable::setTestNow('2026-09-21 10:00:00');
    $this->consumedEvents = [];
    app('events')->listen(AttributionConsumed::class, function (AttributionConsumed $event): void {
        $this->consumedEvents[] = $event;
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    (require __DIR__.'/../../../database/migrations/2026_01_01_000002_create_linkado_pending_attributions_table.php')->down();
    DB::purge('matching');
});

it('discards unconfirmed attribution instead of returning a snapshot', function (array $cookies): void {
    matchingInsert('01ARZ3NDEKTSV4RRFFQ69G5FAV', null);
    $request = matchingRequest($cookies);

    expect(DB::connection('matching')->transaction(fn () => Linkado::attribution()->consume($request)))->toBeNull();
    $row = DB::connection('matching')->table('linkado_pending_attributions')->sole();
    expect($row->click_id)->toBeNull()->and($row->referral_slug)->toBeNull()
        ->and($row->consumed_at)->toBe('2026-09-21 10:00:00')
        ->and($row->captured_at)->toBe('2026-09-21 10:00:00')
        ->and($row->expires_at)->toBe('2026-09-21 10:02:00')
        ->and($this->consumedEvents)->toBeEmpty();
})->with([
    'visitor cookie only' => [[]],
    'visitor A with click B' => [['lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAW']],
    'case differs' => [['lk_click' => '01arz3ndektsv4rrffq69g5fav']],
    'only referral' => [['lk_referral' => 'partner']],
]);

it('returns only the exactly matched valid stored source after commit', function (?string $click, ?string $slug, array $cookies): void {
    matchingInsert($click, $slug);
    $result = DB::connection('matching')->transaction(function () use ($cookies): ?ConsumedAttribution {
        $result = Linkado::attribution()->consume(matchingRequest($cookies));
        expect($this->consumedEvents)->toBeEmpty();

        return $result;
    });
    expect($result)->toBeInstanceOf(ConsumedAttribution::class)
        ->and($result?->clickId)->toBe($click)->and($result?->referralSlug)->toBe($slug)
        ->and($this->consumedEvents)->toHaveCount(1);
})->with([
    'click' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', null, ['lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']],
    'click wins over valid referral' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', null, ['lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'lk_referral' => 'partner']],
    'lowercase ULID retained' => ['01arz3ndektsv4rrffq69g5fav', null, ['lk_click' => '01arz3ndektsv4rrffq69g5fav']],
    'slug' => [null, 'partner-42', ['lk_referral' => 'partner-42']],
    'empty click absent' => [null, 'partner', ['lk_click' => '', 'lk_referral' => 'partner']],
    'single character slug' => [null, 'a', ['lk_referral' => 'a']],
    '100 character slug' => [null, str_repeat('a', 100), ['lk_referral' => str_repeat('a', 100)]],
]);

it('never confirms a slug from query or upgrades it during consume', function (array $cookies): void {
    matchingInsert(null, 'partner');
    $result = DB::connection('matching')->transaction(
        fn () => Linkado::attribution()->consume(matchingRequest($cookies, '/register?ref=partner')),
    );
    expect($result)->toBeNull()->and($this->consumedEvents)->toBeEmpty()
        ->and(DB::connection('matching')->table('linkado_pending_attributions')->sole()->referral_slug)->toBeNull();
})->with([
    'only query' => [[]],
    'mismatched referral' => [['lk_referral' => 'other']],
    'valid click candidate' => [['lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'lk_referral' => 'partner']],
    'invalid click candidate' => [['lk_click' => 'invalid', 'lk_referral' => 'partner']],
]);

it('rejects malformed capture inputs without normalization or fallback', function (string $source, mixed $value): void {
    app(CapturePendingAttribution::class)->handle(
        '01ARZ3NDEKTSV4RRFFQ69G5FAX',
        $source === 'click' ? $value : '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        $source === 'slug' ? $value : 'partner',
    );
    expect(DB::connection('matching')->table('linkado_pending_attributions')->count())->toBe(0);
})->with('malformed attribution identifiers');

it('discards malformed source cookies without falling back to another source', function (string $source, mixed $value): void {
    matchingInsert($source === 'click' ? null : '01ARZ3NDEKTSV4RRFFQ69G5FAV', $source === 'click' ? 'partner' : null);
    $result = DB::connection('matching')->transaction(fn () => Linkado::attribution()->consume(matchingRequest([
        'lk_click' => $source === 'click' ? $value : '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'lk_referral' => $source === 'slug' ? $value : 'partner',
    ])));
    $row = DB::connection('matching')->table('linkado_pending_attributions')->sole();
    expect($result)->toBeNull()->and($row->click_id)->toBeNull()->and($row->referral_slug)->toBeNull()
        ->and($row->consumed_at)->not->toBeNull()->and($this->consumedEvents)->toBeEmpty();
})->with('malformed attribution identifiers');

dataset('malformed attribution identifiers', [
    'click array' => ['click', ['01ARZ3NDEKTSV4RRFFQ69G5FAV']],
    'click empty array' => ['click', []],
    'click integer' => ['click', 123],
    'click boolean' => ['click', false],
    'click whitespace only' => ['click', ' '],
    'click surrounding whitespace' => ['click', ' 01ARZ3NDEKTSV4RRFFQ69G5FAV '],
    'click short' => ['click', '01ARZ3NDEKTSV4RRFFQ69G5FA'],
    'click long' => ['click', '01ARZ3NDEKTSV4RRFFQ69G5FAV0'],
    'click invalid alphabet' => ['click', '01ARZ3NDEKTSV4RRFFQ69G5FAI'],
    'click overflow' => ['click', '81ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'click Unicode' => ['click', '01ARZ3NDEKTSV4RRFFQ69G5FAЖ'],
    'click control' => ['click', "01ARZ3NDEKTSV4RRFFQ69G5FAV\n"],
    'slug array' => ['slug', ['partner']],
    'slug empty array' => ['slug', []],
    'slug integer' => ['slug', 123],
    'slug boolean' => ['slug', false],
    'slug whitespace only' => ['slug', ' '],
    'slug surrounding whitespace' => ['slug', ' partner '],
    'slug uppercase' => ['slug', 'Partner'],
    'slug underscore' => ['slug', 'a_b'],
    'slug double hyphen' => ['slug', 'a--b'],
    'slug leading hyphen' => ['slug', '-partner'],
    'slug trailing hyphen' => ['slug', 'partner-'],
    'slug oversized' => ['slug', str_repeat('a', 101)],
    'slug Unicode' => ['slug', 'партнер'],
    'slug control' => ['slug', "partner\0"],
    'slug newline' => ['slug', "partner\n"],
]);

it('rejects malformed stored rows even if the cookies repeat the stored values', function (?string $click, ?string $slug): void {
    matchingInsert($click, $slug);
    $result = DB::connection('matching')->transaction(fn () => Linkado::attribution()->consume(matchingRequest([
        'lk_click' => $click, 'lk_referral' => $slug,
    ])));
    $row = DB::connection('matching')->table('linkado_pending_attributions')->sole();
    expect($result)->toBeNull()->and($row->click_id)->toBeNull()->and($row->referral_slug)->toBeNull()
        ->and($row->consumed_at)->not->toBeNull()->and($this->consumedEvents)->toBeEmpty();
})->with([
    'neither' => [null, null],
    'both' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'partner'],
    'empty click and slug' => ['', 'partner'],
    'click and empty slug' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', ''],
    'empty slug' => [null, ''],
    'legacy click' => ['click-42', null],
    'padded click' => [' 01ARZ3NDEKTSV4RRFFQ69G5FAV ', null],
    'uppercase slug' => [null, 'Partner'],
    'oversized slug' => [null, str_repeat('a', 101)],
]);

it('does not modify another visitor row for an invalid or unknown visitor cookie', function (mixed $visitor): void {
    matchingInsert('01ARZ3NDEKTSV4RRFFQ69G5FAV', null);
    $before = DB::connection('matching')->table('linkado_pending_attributions')->sole();
    expect(DB::connection('matching')->transaction(fn () => Linkado::attribution()->consume(matchingRequest([
        'linkado_visitor' => $visitor, 'lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]))))->toBeNull()
        ->and(DB::connection('matching')->table('linkado_pending_attributions')->sole())->toEqual($before);
})->with([null, '', 'tampered', '01ARZ3NDEKTSV4RRFFQ69G5FAY', [['array']]]);

it('rolls back a discard with its caller and then permits a legitimate consume', function (): void {
    matchingInsert(null, 'partner');
    $connection = DB::connection('matching');
    $before = $connection->table('linkado_pending_attributions')->sole();
    $connection->beginTransaction();

    try {
        expect(Linkado::attribution()->consume(matchingRequest(['lk_referral' => 'other'])))->toBeNull()
            ->and($connection->table('linkado_pending_attributions')->sole()->consumed_at)->not->toBeNull();
    } finally {
        $connection->rollBack();
    }
    expect($connection->table('linkado_pending_attributions')->sole())->toEqual($before)
        ->and($this->consumedEvents)->toBeEmpty();
    expect($connection->transaction(fn () => Linkado::attribution()->consume(matchingRequest(['lk_referral' => 'partner'])))?->referralSlug)->toBe('partner')
        ->and($this->consumedEvents)->toHaveCount(1);
});

it('prevents consume and recapture after a committed discard until the original expiry', function (): void {
    matchingInsert(null, 'partner');
    $connection = DB::connection('matching');
    $connection->transaction(fn () => Linkado::attribution()->consume(matchingRequest()));
    CarbonImmutable::setTestNow('2026-09-21 10:01:59');
    app(CapturePendingAttribution::class)->handle('01ARZ3NDEKTSV4RRFFQ69G5FAX', '01ARZ3NDEKTSV4RRFFQ69G5FAV', null);
    expect($connection->transaction(fn () => Linkado::attribution()->consume(matchingRequest(['lk_referral' => 'partner']))))->toBeNull()
        ->and($connection->table('linkado_pending_attributions')->sole()->expires_at)->toBe('2026-09-21 10:02:00')
        ->and($this->consumedEvents)->toBeEmpty();
    CarbonImmutable::setTestNow('2026-09-21 10:02:00');
    app(CapturePendingAttribution::class)->handle('01ARZ3NDEKTSV4RRFFQ69G5FAX', null, 'new-partner');
    expect($connection->transaction(fn () => Linkado::attribution()->consume(matchingRequest(['lk_referral' => 'new-partner'])))?->referralSlug)->toBe('new-partner');
});

function matchingInsert(?string $click, ?string $slug): void
{
    DB::connection('matching')->table('linkado_pending_attributions')->insert([
        'visitor_hash' => hash('sha256', '01ARZ3NDEKTSV4RRFFQ69G5FAX'),
        'click_id' => $click, 'referral_slug' => $slug,
        'captured_at' => now(), 'expires_at' => now()->addSeconds(120),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param array<string, mixed> $cookies */
function matchingRequest(array $cookies = [], string $uri = '/register'): Request
{
    return Request::create($uri, 'POST', cookies: ['linkado_visitor' => '01ARZ3NDEKTSV4RRFFQ69G5FAX', ...$cookies]);
}
