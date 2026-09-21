<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\OutboxEventDelivered;
use Linkado\Laravel\Events\OutboxEventPermanentlyFailed;
use Linkado\Laravel\Events\OutboxRetryScheduled;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Delivery\ClaimedOutboxEvent;
use Linkado\Laravel\Support\Delivery\DeliveryResult;
use Linkado\Laravel\Support\Delivery\FailureClassifier;
use Linkado\Laravel\Support\Delivery\RetrySchedule;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\PhpSdk\LinkadoConnector;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use UnexpectedValueException;

final readonly class DeliverClaimedOutboxEvent
{
    private const string CORRUPTION_CODE = 'payload_corrupt';

    public function __construct(
        private Application $application,
        private EventPayloadCodec $codec,
        private CompleteOutboxClaim $completeOutboxClaim,
        private FailureClassifier $failureClassifier,
        private RetrySchedule $retrySchedule,
        private Dispatcher $events,
    ) {}

    public function handle(ClaimedOutboxEvent $claimed): ?CarbonImmutable
    {
        try {
            $event = $this->codec->decodeStored(
                type: $claimed->eventType,
                eventId: $claimed->eventId,
                payload: $claimed->payload,
                sha256: $claimed->payloadSha256,
            );
        } catch (UnexpectedValueException) {
            $this->completeCorrupt($claimed);

            return null;
        }

        try {
            $response = $this->application
                ->make(LinkadoConnector::class)
                ->events()
                ->send($event);
        } catch (FatalRequestException|RequestException $failure) {
            return $this->completeFailure($claimed, $failure);
        }

        $this->completeDelivered($claimed, DeliveryResult::fromResponse($response));

        return null;
    }

    private function completeCorrupt(ClaimedOutboxEvent $claimed): bool
    {
        $lifecycleEvent = null;
        $completed = $this->completeOutboxClaim->complete(
            $claimed,
            function (OutboxEvent $event, OutboxAttempt $attempt) use (&$lifecycleEvent, $claimed): void {
                $now = CarbonImmutable::now();

                $event->status = OutboxStatus::Failed;
                $event->next_attempt_at = null;
                $event->terminal_at = $now;
                $event->setAttribute('last_error_code', self::CORRUPTION_CODE);
                $event->setAttribute('last_error_class', null);
                $event->setAttribute('last_error_message', null);

                $attempt->outcome = AttemptOutcome::Failed;
                $attempt->setAttribute('error_code', self::CORRUPTION_CODE);
                $attempt->setAttribute('error_class', null);
                $attempt->setAttribute('error_message', null);

                $lifecycleEvent = new OutboxEventPermanentlyFailed(
                    eventId: $claimed->eventId,
                    sourceKey: $event->source_key,
                    status: OutboxStatus::Failed,
                    attemptNumber: $claimed->attemptNumber,
                    terminalAt: $now,
                    errorCode: self::CORRUPTION_CODE,
                );
            },
        );

        if ($completed && $lifecycleEvent instanceof OutboxEventPermanentlyFailed) {
            $this->events->dispatch($lifecycleEvent);
        }

        return $completed;
    }

    private function completeDelivered(ClaimedOutboxEvent $claimed, DeliveryResult $result): bool
    {
        $lifecycleEvent = null;
        $completed = $this->completeOutboxClaim->complete(
            $claimed,
            function (OutboxEvent $event, OutboxAttempt $attempt) use (&$lifecycleEvent, $claimed, $result): void {
                $now = CarbonImmutable::now();

                $event->status = OutboxStatus::Delivered;
                $event->next_attempt_at = null;
                $event->delivered_at = $now;
                $event->terminal_at = $now;
                $event->setAttribute('remote_event_id', $result->remoteEventId);
                $event->setAttribute('remote_status', $result->remoteStatus);
                $event->setAttribute('remote_warnings', $result->remoteWarnings);
                $event->setAttribute('last_error_code', null);
                $event->setAttribute('last_error_class', null);
                $event->setAttribute('last_error_message', null);

                $attempt->outcome = AttemptOutcome::Delivered;
                $attempt->setAttribute('remote_event_id', $result->remoteEventId);
                $attempt->setAttribute('remote_status', $result->remoteStatus);
                $attempt->setAttribute('remote_warnings', $result->remoteWarnings);
                $attempt->setAttribute('error_code', null);
                $attempt->setAttribute('error_class', null);
                $attempt->setAttribute('error_message', null);

                $lifecycleEvent = new OutboxEventDelivered(
                    eventId: $claimed->eventId,
                    sourceKey: $event->source_key,
                    status: OutboxStatus::Delivered,
                    attemptNumber: $claimed->attemptNumber,
                    deliveredAt: $now,
                );
            },
        );

        if ($completed && $lifecycleEvent instanceof OutboxEventDelivered) {
            $this->events->dispatch($lifecycleEvent);
        }

        return $completed;
    }

    private function completeFailure(
        ClaimedOutboxEvent $claimed,
        FatalRequestException|RequestException $failure,
    ): ?CarbonImmutable {
        $now = CarbonImmutable::now();
        $retryAfter = $this->failureClassifier->retryAfter($failure);
        $retryAfterSeconds = $retryAfter === null
            ? null
            : $this->retrySchedule->retryAfterSeconds($retryAfter, $now);
        $nextAttemptAt = $this->failureClassifier->isRetryable($failure)
            ? $this->retrySchedule->nextAttemptAt(
                attemptNumber: $claimed->attemptNumber,
                createdAt: $claimed->createdAt,
                now: $now,
                retryAfterSeconds: $retryAfterSeconds,
            )
            : null;
        $retryScheduled = $nextAttemptAt !== null;
        $httpStatus = $this->failureClassifier->httpStatus($failure);
        $errorCode = $this->failureClassifier->code($failure);
        $errorClass = $this->failureClassifier->exceptionClass($failure);

        $lifecycleEvent = null;
        $completed = $this->completeOutboxClaim->complete(
            $claimed,
            function (OutboxEvent $event, OutboxAttempt $attempt) use (
                &$lifecycleEvent,
                $claimed,
                $errorClass,
                $errorCode,
                $httpStatus,
                $nextAttemptAt,
                $now,
                $retryAfterSeconds,
                $retryScheduled,
            ): void {
                $event->status = $retryScheduled ? OutboxStatus::Pending : OutboxStatus::Failed;
                $event->next_attempt_at = $nextAttemptAt;
                $event->terminal_at = $retryScheduled ? null : $now;
                $event->setAttribute('last_error_code', $errorCode);
                $event->setAttribute('last_error_class', $errorClass);
                $event->setAttribute('last_error_message', null);

                $attempt->outcome = $retryScheduled
                    ? AttemptOutcome::RetryScheduled
                    : AttemptOutcome::Failed;
                $attempt->setAttribute('http_status', $httpStatus);
                $attempt->setAttribute('retry_after_seconds', $retryAfterSeconds);
                $attempt->setAttribute('error_code', $errorCode);
                $attempt->setAttribute('error_class', $errorClass);
                $attempt->setAttribute('error_message', null);

                $lifecycleEvent = $retryScheduled
                    ? new OutboxRetryScheduled(
                        eventId: $claimed->eventId,
                        sourceKey: $event->source_key,
                        status: OutboxStatus::Pending,
                        attemptNumber: $claimed->attemptNumber,
                        nextAttemptAt: $nextAttemptAt,
                        errorCode: $errorCode,
                    )
                    : new OutboxEventPermanentlyFailed(
                        eventId: $claimed->eventId,
                        sourceKey: $event->source_key,
                        status: OutboxStatus::Failed,
                        attemptNumber: $claimed->attemptNumber,
                        terminalAt: $now,
                        errorCode: $errorCode,
                    );
            },
        );

        if ($completed && ($lifecycleEvent instanceof OutboxRetryScheduled || $lifecycleEvent instanceof OutboxEventPermanentlyFailed)) {
            $this->events->dispatch($lifecycleEvent);
        }

        return $completed && $retryScheduled ? $nextAttemptAt : null;
    }
}
