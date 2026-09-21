<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\OutboxEventClaimed;
use Linkado\Laravel\Events\OutboxEventPermanentlyFailed;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Delivery\ClaimedOutboxEvent;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\Enums\EventType;

final readonly class ClaimOutboxEvent
{
    public function __construct(
        private LinkadoConfiguration $configuration,
        private Dispatcher $events,
    ) {}

    public function claim(string $eventId): ?ClaimedOutboxEvent
    {
        $connection = DB::connection($this->configuration->connection());

        return $connection->transaction(function () use ($connection, $eventId): ?ClaimedOutboxEvent {
            $event = OutboxEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            $now = CarbonImmutable::now();

            if ($event === null || ! $this->isClaimable($event, $now)) {
                return null;
            }

            if ($this->automaticLimitReached($event, $now)) {
                $this->finishExpiredAttempt($event, $now, AttemptOutcome::Failed);
                $event->status = OutboxStatus::Failed;
                $event->next_attempt_at = null;
                $event->claimed_at = null;
                $event->claim_token = null;
                $event->terminal_at = $now;
                $event->setAttribute('last_error_code', 'retry_exhausted');
                $event->save();
                $connection->afterCommit(fn (): mixed => $this->events->dispatch(new OutboxEventPermanentlyFailed(
                    eventId: $event->event_id,
                    sourceKey: $event->source_key,
                    status: OutboxStatus::Failed,
                    attemptNumber: $event->attempt_count,
                    terminalAt: $now,
                    errorCode: 'retry_exhausted',
                )));

                return null;
            }

            $this->finishExpiredAttempt($event, $now);

            $attemptNumber = $event->attempt_count + 1;
            $claimToken = strtolower((string) Str::ulid());

            $storedType = $event->getRawOriginal('event_type');
            $eventType = is_string($storedType) ? EventType::tryFrom($storedType) : null;

            if ($this->configuration->mode() !== DeliveryMode::Live || $eventType === null) {
                $outcome = $eventType === null ? AttemptOutcome::Failed : AttemptOutcome::PackageDisabled;
                $errorCode = $eventType === null ? 'payload_corrupt' : AttemptOutcome::PackageDisabled->value;
                $this->markTerminal($event, $attemptNumber, $claimToken, $now, $outcome, $errorCode);
                $connection->afterCommit(fn (): mixed => $this->events->dispatch(new OutboxEventPermanentlyFailed(
                    eventId: $event->event_id,
                    sourceKey: $event->source_key,
                    status: OutboxStatus::Failed,
                    attemptNumber: $attemptNumber,
                    terminalAt: $now,
                    errorCode: $errorCode,
                )));

                return null;
            }

            $event->status = OutboxStatus::Delivering;
            $event->attempt_count = $attemptNumber;
            $event->next_attempt_at = null;
            $event->claimed_at = $now;
            $event->claim_token = $claimToken;
            $event->save();

            $this->createAttempt($event, $attemptNumber, $claimToken, $now);
            $connection->afterCommit(fn (): mixed => $this->events->dispatch(new OutboxEventClaimed(
                eventId: $event->event_id,
                sourceKey: $event->source_key,
                status: OutboxStatus::Delivering,
                attemptNumber: $attemptNumber,
                claimedAt: $now,
            )));

            return new ClaimedOutboxEvent(
                eventId: $event->event_id,
                eventType: $eventType,
                payload: $event->payload,
                payloadSha256: $event->payload_sha256,
                attemptNumber: $attemptNumber,
                claimToken: $claimToken,
                createdAt: $event->created_at,
            );
        });
    }

    private function isClaimable(OutboxEvent $event, CarbonImmutable $now): bool
    {
        if ($event->delivery_mode !== DeliveryMode::Live) {
            return false;
        }

        if ($event->status === OutboxStatus::Pending) {
            return $event->next_attempt_at === null
                || $event->next_attempt_at->lessThanOrEqualTo($now);
        }

        return $event->status === OutboxStatus::Delivering
            && $event->claimed_at !== null
            && $event->claimed_at->lessThanOrEqualTo(
                $now->subSeconds($this->configuration->deliveryClaimTimeoutSeconds()),
            );
    }

    private function automaticLimitReached(OutboxEvent $event, CarbonImmutable $now): bool
    {
        if ($event->attempt_count < $this->configuration->deliveryMaxAttempts()
            && $now->lessThanOrEqualTo($event->created_at->addSeconds($this->configuration->deliveryRetryWindowSeconds()))) {
            return false;
        }

        // An audited manual retry authorizes one new send, never automatic reclaims.
        return $event->status !== OutboxStatus::Pending
            || ! OutboxAttempt::query()
                ->where('outbox_event_id', $event->getKey())
                ->where('number', $event->attempt_count)
                ->where('outcome', AttemptOutcome::ManuallyRetried->value)
                ->exists();
    }

    private function finishExpiredAttempt(
        OutboxEvent $event,
        CarbonImmutable $now,
        AttemptOutcome $outcome = AttemptOutcome::RetryScheduled,
    ): void {
        if ($event->status !== OutboxStatus::Delivering || $event->claim_token === null) {
            return;
        }

        OutboxAttempt::query()
            ->where('outbox_event_id', $event->getKey())
            ->where('claim_token', $event->claim_token)
            ->whereNull('finished_at')
            ->update([
                'outcome' => $outcome->value,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /** @param int<1, max> $attemptNumber */
    private function markTerminal(
        OutboxEvent $event,
        int $attemptNumber,
        string $claimToken,
        CarbonImmutable $now,
        AttemptOutcome $outcome,
        string $errorCode,
    ): void {
        $event->status = OutboxStatus::Failed;
        $event->attempt_count = $attemptNumber;
        $event->next_attempt_at = null;
        $event->claimed_at = null;
        $event->claim_token = null;
        $event->terminal_at = $now;
        $event->setAttribute('last_error_code', $errorCode);
        $event->save();

        $this->createAttempt(
            $event,
            $attemptNumber,
            $claimToken,
            $now,
            $outcome,
            $errorCode,
        );
    }

    /** @param int<1, max> $attemptNumber */
    private function createAttempt(
        OutboxEvent $event,
        int $attemptNumber,
        string $claimToken,
        CarbonImmutable $now,
        ?AttemptOutcome $outcome = null,
        ?string $errorCode = null,
    ): void {
        OutboxAttempt::query()->create([
            'outbox_event_id' => $event->getKey(),
            'number' => $attemptNumber,
            'claim_token' => $claimToken,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'started_at' => $now,
            'finished_at' => $outcome === null ? null : $now,
        ]);
    }
}
