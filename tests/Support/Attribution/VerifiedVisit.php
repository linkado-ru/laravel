<?php

declare(strict_types=1);

namespace Linkado\Laravel\Tests\Support\Attribution;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Linkado\PhpSdk\Requests\ResolveTrackingClickRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

final class VerifiedVisit
{
    public static function click(string $source): string
    {
        return Str::isUlid($source) ? $source : '0'.strtoupper(substr(hash('sha256', $source), 0, 25));
    }

    public static function request(string $visitor, string $source = 'partner'): Request
    {
        return Request::create('/register', cookies: [
            'linkado_visitor' => $visitor,
            'lk_click' => self::click($source),
        ]);
    }

    public static function fake(string $program = 'synthetic-test-program', int $ttl = 120): MockClient
    {
        $visits = [];

        return MockClient::global([
            ResolveTrackingClickRequest::class => static function (PendingRequest $pending) use ($program, $ttl, &$visits): MockResponse {
                $request = $pending->getRequest();
                assert($request instanceof ResolveTrackingClickRequest);
                $click = $request->data->click_id;
                $visits[$click] ??= [
                    'click_id' => $click, 'program_key' => $program, 'referral_slug' => 'partner',
                    'visit_order' => count($visits) + 1,
                    'created_at' => CarbonImmutable::now()->toIso8601String(),
                    'expires_at' => CarbonImmutable::now()->addSeconds($ttl)->toIso8601String(),
                ];

                return MockResponse::make(['data' => $visits[$click]]);
            },
        ]);
    }
}
