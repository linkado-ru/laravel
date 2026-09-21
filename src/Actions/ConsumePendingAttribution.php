<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Linkado\Laravel\Events\AttributionConsumed;
use Linkado\Laravel\Support\Attribution\AttributionIdentifiers;
use Linkado\Laravel\Support\Attribution\ConsumedAttribution;
use Linkado\Laravel\Support\Attribution\IdentityLock;
use Linkado\Laravel\Support\Database\RequiresActiveTransaction;
use Linkado\Laravel\Support\LinkadoConfiguration;

final readonly class ConsumePendingAttribution
{
    public function __construct(
        private RequiresActiveTransaction $transaction,
        private LinkadoConfiguration $configuration,
        private Dispatcher $events,
        private IdentityLock $identityLock,
    ) {}

    public function handle(Request $request): ?ConsumedAttribution
    {
        $connection = $this->transaction->ensure();
        $snapshot = null;
        $this->identityLock->run($request, function (?string $identityHash) use ($connection, $request, &$snapshot): void {
            $snapshot = $this->consume($connection, $request, $identityHash);
        });

        return $snapshot;
    }

    private function consume(Connection $connection, Request $request, ?string $identityHash): ?ConsumedAttribution
    {
        $visitorId = $request->cookie($this->configuration->trackingVisitorCookie());

        if (! is_string($visitorId) || ! Str::isUlid($visitorId)) {
            return null;
        }

        $row = $connection->table('linkado_pending_attributions')
            ->where('visitor_hash', hash('sha256', $visitorId))
            ->lockForUpdate()
            ->first();

        if ($row === null || $row->identity_hash !== $identityHash) {
            return null;
        }

        $expiresAt = $row->expires_at;
        $consumedAt = CarbonImmutable::now();

        if ((! is_string($expiresAt) && ! $expiresAt instanceof DateTimeInterface)
            || CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo($consumedAt)) {
            $connection->table('linkado_pending_attributions')->where('id', $row->id)->delete();

            return null;
        }

        if ($row->consumed_at !== null) {
            return null;
        }

        $clickCookie = $request->cookie($this->configuration->trackingClickCookie());
        $referralCookie = $request->cookie($this->configuration->trackingReferralCookie());
        $matches = AttributionIdentifiers::validCandidates($clickCookie, $referralCookie)
            && ((AttributionIdentifiers::click($row->click_id) && $row->referral_slug === null && $row->click_id === $clickCookie)
                || ($row->click_id === null && AttributionIdentifiers::referral($row->referral_slug)
                    && AttributionIdentifiers::absent($clickCookie) && $row->referral_slug === $referralCookie));

        $connection->table('linkado_pending_attributions')->where('id', $row->id)->update([
            'click_id' => null,
            'referral_slug' => null,
            'consumed_at' => $consumedAt,
            'updated_at' => $consumedAt,
        ]);

        if (! $matches) {
            return null;
        }

        $connection->afterCommit(fn (): mixed => $this->events->dispatch(new AttributionConsumed($consumedAt)));

        return new ConsumedAttribution(
            clickId: is_string($row->click_id) ? $row->click_id : null,
            referralSlug: is_string($row->referral_slug) ? $row->referral_slug : null,
        );
    }
}
