<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Support\Attribution\ConsumedAttribution;
use Linkado\Laravel\Support\Database\RequiresActiveTransaction;
use Linkado\Laravel\Support\LinkadoConfiguration;

final readonly class ConsumePendingAttribution
{
    public function __construct(
        private RequiresActiveTransaction $transaction,
        private LinkadoConfiguration $configuration,
        private Dispatcher $events,
    ) {}

    public function handle(Request $request): ?ConsumedAttribution
    {
        $connection = $this->transaction->ensure();
        $visitorId = $request->cookie($this->configuration->trackingVisitorCookie());

        if (! is_string($visitorId) || ! Str::isUlid($visitorId)) {
            return null;
        }

        $row = $connection->table('linkado_pending_attributions')
            ->where('visitor_hash', hash('sha256', $visitorId))
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return null;
        }

        $deleted = $connection->table('linkado_pending_attributions')
            ->where('id', $row->id)
            ->delete();

        $expiresAt = $row->expires_at;

        if ($deleted !== 1
            || (! is_string($expiresAt) && ! $expiresAt instanceof DateTimeInterface)
            || CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(now())) {
            return null;
        }

        $consumedAt = CarbonImmutable::now();
        $connection->afterCommit(fn (): mixed => $this->events->dispatch(new AttributionConsumed($consumedAt)));

        return new ConsumedAttribution(
            clickId: is_string($row->click_id) ? $row->click_id : null,
            referralSlug: is_string($row->referral_slug) ? $row->referral_slug : null,
        );
    }
}
