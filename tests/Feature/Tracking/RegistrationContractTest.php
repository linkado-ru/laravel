<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Linkado\Laravel\Contracts\LocksLinkadoAttributionIdentity;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Tests\Support\Attribution\HostIdentityAdapter;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;
use Linkado\PhpSdk\LinkadoConnector;
use Linkado\PhpSdk\Requests\SendEventRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 00:00:00');
    config()->set('database.connections.registration_contract', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    config()->set('linkado.connection', 'registration_contract');
    config()->set('linkado.mode', 'live');
    config()->set('linkado.features.tracking', true);
    config()->set('linkado.features.customer_events', true);
    config()->set('linkado.program_key', 'public-registration-program');
    config()->set('linkado.token', 'synthetic-registration-credential');
    config()->set('linkado.base_url', 'https://api.example.test/api/v1');
    config()->set('linkado.tracking.script_url', 'https://cdn.example.test/tracking.js');
    config()->set('linkado.tracking.endpoint_url', 'https://api.example.test/track');
    DB::purge('registration_contract');
    $this->connection = DB::connection('registration_contract');
    foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    $this->connection->getSchemaBuilder()->create('anonymous_identities', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->boolean('claimed')->default(false);
    });
    $this->connection->table('anonymous_identities')->insert(['key' => 'anonymous-a']);
    Bus::fake([DeliverOutboxEvent::class]);
    Event::fake([AttributionConsumed::class]);
});

afterEach(function (): void {
    DB::purge('registration_contract');
    CarbonImmutable::setTestNow();
});

/** @param array<string, string> $cookies */
function registrationRequest(array $cookies, string $identity = 'anonymous-a'): Request
{
    $request = Request::create('/register?ref=later-partner', cookies: [
        'linkado_visitor' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', ...$cookies,
    ]);
    $request->attributes->set('anonymous_identity', $identity);

    return $request;
}

function registrationCapture(Request $request): void
{
    app(CapturePendingAttribution::class)->handle($request, fn (): Response => new Response('host'));
}

/** @return array<string, mixed> */
function registrationBrowser(): array
{
    $html = Blade::render('@linkadoTracking'.PHP_EOL.'{{-- '.Str::ulid().' --}}', deleteCachedView: true);
    $process = new Process(['node', __DIR__.'/../../Support/Tracking/run-hosted-script.mjs']);
    $process->setInput(json_encode([['html' => $html]], JSON_THROW_ON_ERROR));
    $process->setTimeout(10)->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)[0];
}

it('preserves the browser first window through registration rollback or commit and a remote outage', function (bool $identity, bool $rollback): void {
    if ($identity) {
        app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    }
    $browser = registrationBrowser();
    expect($browser['calls'][0]['body'])->toBe([
        'program_key' => 'public-registration-program', 'referral_slug' => 'first-partner',
    ]);
    registrationCapture(registrationRequest($browser['synchronousCookies']));
    $first = $this->connection->table('linkado_pending_attributions')->sole();
    expect($first->referral_slug)->toBe('first-partner')
        ->and($first->captured_at)->toBe('2026-09-21 00:00:00')
        ->and($first->expires_at)->toBe('2026-10-21 00:00:00');

    CarbonImmutable::setTestNow('2026-09-21 01:00:00');
    registrationCapture(registrationRequest(['lk_referral' => 'later-partner']));
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($first);
    $request = registrationRequest($browser['cookies']);
    registrationCapture($request);
    registrationCapture(registrationRequest(['lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAW']));
    $upgraded = $this->connection->table('linkado_pending_attributions')->sole();
    expect($upgraded->click_id)->toBe('01K5M4W0000000000000000001')
        ->and($upgraded->referral_slug)->toBeNull()
        ->and($upgraded->captured_at)->toBe($first->captured_at)
        ->and($upgraded->expires_at)->toBe($first->expires_at);

    $this->connection->beginTransaction();
    $snapshot = Linkado::attribution()->consume($request);
    expect($snapshot?->clickId)->toBe('01K5M4W0000000000000000001');
    $this->connection->table('anonymous_identities')->where('key', 'anonymous-a')->update(['claimed' => true]);
    $factory = fn (string $eventId): CustomerCreatedEventData => new CustomerCreatedEventData(
        event_id: $eventId, program_key: 'public-registration-program', occurred_at: now(),
        external_customer_id: 'customer-registered', click_id: $snapshot?->clickId,
    );
    $event = Linkado::record('registration:anonymous-a', $factory);
    expect(Linkado::record('registration:anonymous-a', $factory)?->getKey())->toBe($event?->getKey())
        ->and(app()->resolved(LinkadoConnector::class))->toBeFalse()
        ->and(OutboxEvent::query()->count())->toBe(1);
    Bus::assertNotDispatched(DeliverOutboxEvent::class);
    Event::assertNotDispatched(AttributionConsumed::class);

    if ($rollback) {
        $this->connection->rollBack();
        expect(OutboxEvent::query()->count())->toBe(0)
            ->and($this->connection->table('anonymous_identities')->sole()->claimed)->toBe(0)
            ->and($this->connection->table('linkado_pending_attributions')->sole())->toEqual($upgraded);
        Bus::assertNotDispatched(DeliverOutboxEvent::class);
        Event::assertNotDispatched(AttributionConsumed::class);

        return;
    }
    $this->connection->commit();
    Bus::assertDispatchedTimes(DeliverOutboxEvent::class, 1);
    Event::assertDispatchedTimes(AttributionConsumed::class, 1);
    $marker = $this->connection->table('linkado_pending_attributions')->sole();
    expect($marker->click_id)->toBeNull()->and($marker->referral_slug)->toBeNull()
        ->and($marker->expires_at)->toBe($first->expires_at);
    registrationCapture($request);
    expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($marker);

    $payload = $event->payload;
    $mock = new MockClient([SendEventRequest::class => MockResponse::make(['message' => 'Unavailable'], 503)]);
    app(LinkadoConnector::class)->withMockClient($mock);
    app()->call([new DeliverOutboxEvent($event->event_id), 'handle']);
    $stored = $event->fresh();
    expect($mock->getRecordedResponses())->toHaveCount(1)
        ->and($this->connection->table('anonymous_identities')->sole()->claimed)->toBe(1)
        ->and($stored->status)->toBe(OutboxStatus::Pending)
        ->and($stored->attempt_count)->toBe(1)
        ->and($stored->next_attempt_at)->not->toBeNull()
        ->and($stored->payload)->toBe($payload)
        ->and($stored->payload_sha256)->toBe(hash('sha256', $payload));
})->with([false, true])->with([false, true]);

it('rejects mismatched browser cookies or identity after the hosted script completed', function (bool $foreignIdentity): void {
    app()->instance(LocksLinkadoAttributionIdentity::class, new HostIdentityAdapter($this->connection));
    $this->connection->table('anonymous_identities')->insert(['key' => 'anonymous-b']);
    $browser = registrationBrowser();
    registrationCapture(registrationRequest($browser['cookies']));
    $row = $this->connection->table('linkado_pending_attributions')->sole();
    $request = registrationRequest(
        ['lk_click' => '01ARZ3NDEKTSV4RRFFQ69G5FAW'],
        $foreignIdentity ? 'anonymous-b' : 'anonymous-a',
    );
    $this->connection->transaction(function () use ($request): void {
        expect(Linkado::attribution()->consume($request))->toBeNull();
    });

    if ($foreignIdentity) {
        expect($this->connection->table('linkado_pending_attributions')->sole())->toEqual($row);
    } else {
        $marker = $this->connection->table('linkado_pending_attributions')->sole();
        expect($marker->click_id)->toBeNull()->and($marker->consumed_at)->not->toBeNull()
            ->and($marker->expires_at)->toBe($row->expires_at);
    }
    Event::assertNotDispatched(AttributionConsumed::class);
})->with([false, true]);
