<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Delivery\ClaimedOutboxEvent;
use Linkado\Laravel\Support\LinkadoConfiguration;

final readonly class CompleteOutboxClaim
{
    public function __construct(private LinkadoConfiguration $configuration) {}

    /** @param Closure(OutboxEvent, OutboxAttempt): void $completion */
    public function complete(ClaimedOutboxEvent $claimed, Closure $completion): bool
    {
        $connection = DB::connection($this->configuration->connection());

        return $connection->transaction(function () use ($claimed, $completion): bool {
            $event = OutboxEvent::query()
                ->where('event_id', $claimed->eventId)
                ->where('status', OutboxStatus::Delivering->value)
                ->where('claim_token', $claimed->claimToken)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return false;
            }

            $attempt = OutboxAttempt::query()
                ->where('outbox_event_id', $event->getKey())
                ->where('number', $claimed->attemptNumber)
                ->where('claim_token', $claimed->claimToken)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                return false;
            }

            $completion($event, $attempt);

            $event->claimed_at = null;
            $event->claim_token = null;
            $event->save();

            $attempt->finished_at ??= CarbonImmutable::now();
            $attempt->save();

            return true;
        });
    }
}
