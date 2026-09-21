<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\OutboxRetryScheduled;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxAttempt;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\Enums\EventType;
use Throwable;
use UnexpectedValueException;

final readonly class RetryOutboxEvent
{
    public const string OPERATOR_REQUIRED = 'operator_required';

    public const string REASON_REQUIRED = 'reason_required';

    public const string MODE_OFF = 'mode_off';

    public const string NOT_FOUND = 'not_found';

    public const string SHADOW = 'shadow';

    public const string DELIVERED = 'delivered';

    public const string ACTIVE = 'active';

    public const string CORRUPT = 'corrupt';

    public const string CONFLICT = 'conflict';

    private const string AUDIT_CODE = 'manual_retry';

    private const int OPERATOR_LIMIT = 255;

    private const int REASON_LIMIT = 1000;

    public function __construct(
        private LinkadoConfiguration $configuration,
        private EventPayloadCodec $codec,
        private BusDispatcher $bus,
        private CacheRepository $cache,
        private Dispatcher $events,
    ) {}

    public function handle(string $eventId, string $operator, string $reason): OutboxEvent
    {
        $operator = $this->sanitize($operator, self::OPERATOR_LIMIT);
        $reason = $this->sanitize($reason, self::REASON_LIMIT);

        if ($operator === '') {
            throw new DomainException(self::OPERATOR_REQUIRED);
        }

        if ($reason === '') {
            throw new DomainException(self::REASON_REQUIRED);
        }

        if ($this->configuration->mode() !== DeliveryMode::Live) {
            throw new DomainException(self::MODE_OFF);
        }

        $connection = DB::connection($this->configuration->connection());

        return $connection->transaction(function () use ($connection, $eventId, $operator, $reason): OutboxEvent {
            $event = OutboxEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw new DomainException(self::NOT_FOUND);
            }

            $this->ensureRetryable($event);

            $now = CarbonImmutable::now();
            $attemptNumber = $event->attempt_count + 1;
            $claimToken = strtolower((string) Str::ulid());

            $event->status = OutboxStatus::Pending;
            $event->attempt_count = $attemptNumber;
            $event->next_attempt_at = $now;
            $event->claimed_at = null;
            $event->claim_token = null;
            $event->terminal_at = null;
            $event->save();

            OutboxAttempt::query()->create([
                'outbox_event_id' => $event->getKey(),
                'number' => $attemptNumber,
                'claim_token' => $claimToken,
                'outcome' => AttemptOutcome::ManuallyRetried,
                'error_code' => self::AUDIT_CODE,
                'error_class' => null,
                'error_message' => json_encode([
                    'operator' => $operator,
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'started_at' => $now,
                'finished_at' => $now,
            ]);

            $queue = $this->configuration->queue();
            $connection->afterCommit(function () use ($eventId, $event, $attemptNumber, $now, $queue): bool {
                $this->events->dispatch(new OutboxRetryScheduled(
                    eventId: $eventId,
                    sourceKey: $event->source_key,
                    status: OutboxStatus::Pending,
                    attemptNumber: $attemptNumber,
                    nextAttemptAt: $now,
                    errorCode: self::AUDIT_CODE,
                ));

                return $this->dispatch($eventId, $queue);
            });

            return $event;
        });
    }

    private function ensureRetryable(OutboxEvent $event): void
    {
        if ($event->delivery_mode === DeliveryMode::Shadow || $event->status === OutboxStatus::Shadow) {
            throw new DomainException(self::SHADOW);
        }

        if ($event->status === OutboxStatus::Delivered) {
            throw new DomainException(self::DELIVERED);
        }

        if ($event->status === OutboxStatus::Pending || $event->status === OutboxStatus::Delivering) {
            throw new DomainException(self::ACTIVE);
        }

        try {
            $storedType = $event->getRawOriginal('event_type');
            $type = is_string($storedType) ? EventType::tryFrom($storedType) : null;

            if ($type === null) {
                throw new UnexpectedValueException('The stored Linkado event type is invalid.');
            }

            $this->codec->decodeStored(
                type: $type,
                eventId: $event->event_id,
                payload: $event->payload,
                sha256: $event->payload_sha256,
            );
        } catch (UnexpectedValueException) {
            throw new DomainException(self::CORRUPT);
        }

        if ($event->getAttribute('last_error_code') === 'http_409') {
            throw new DomainException(self::CONFLICT);
        }
    }

    private function sanitize(string $value, int $limit): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? '';

        return trim(mb_substr($value, 0, $limit));
    }

    private function dispatch(string $eventId, ?string $queue): bool
    {
        $job = new DeliverOutboxEvent($eventId);

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        $lock = new UniqueLock($this->cache);
        $lockAcquired = false;

        try {
            $lockAcquired = $lock->acquire($job);

            if (! $lockAcquired) {
                return false;
            }

            $this->bus->dispatch($job);

            return true;
        } catch (Throwable) {
            if ($lockAcquired) {
                try {
                    $lock->release($job);
                } catch (Throwable) {
                }
            }

            return false;
        }
    }
}
