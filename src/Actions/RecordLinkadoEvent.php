<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\EligibilityEvaluationFailed;
use Linkado\Laravel\Events\OutboxDispatchFailed;
use Linkado\Laravel\Events\OutboxEventRecorded;
use Linkado\Laravel\Events\OutboxPayloadConflictDetected;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\Database\RequiresActiveTransaction;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\Events\EventFeatureMap;
use Linkado\Laravel\Support\Events\EventPayload;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\DataObjects\EventData;
use Linkado\PhpSdk\Enums\EventType;
use RuntimeException;
use Throwable;

final readonly class RecordLinkadoEvent
{
    public function __construct(
        private RequiresActiveTransaction $transaction,
        private EventPayloadCodec $codec,
        private Dispatcher $events,
        private LinkadoConfiguration $configuration,
        private EventFeatureMap $featureMap,
        private BusDispatcher $bus,
        private CacheRepository $cache,
        /** @var Closure(): DeterminesLinkadoEligibility */
        private Closure $eligibilityResolver,
    ) {}

    /** @param Closure(string): EventData $eventFactory */
    public function handle(string $sourceKey, Closure $eventFactory): ?OutboxEvent
    {
        $connection = $this->transaction->ensure();
        $mode = $this->configuration->mode();

        if ($mode === DeliveryMode::Off) {
            return null;
        }

        $existing = $this->findBySourceKey($sourceKey);
        $eventId = $existing instanceof OutboxEvent
            ? $this->eventId($existing)
            : strtolower((string) Str::ulid());
        $event = $eventFactory($eventId);

        if ($event->event_id !== $eventId) {
            throw new InvalidArgumentException('The Linkado event factory must use the provided event ID.');
        }

        $feature = $this->featureMap->featureFor($event->type());

        if (! $this->configuration->featureEnabled($feature)) {
            return null;
        }

        try {
            $eligible = ($this->eligibilityResolver)()->allows(
                $feature,
                new EligibilityContext(event: $event),
            );
        } catch (Throwable $exception) {
            $this->events->dispatch(new EligibilityEvaluationFailed(
                feature: $feature,
                eventType: $event->type(),
                exceptionClass: $exception::class,
            ));

            return null;
        }

        if (! $eligible) {
            return null;
        }

        $payload = $this->codec->encode($event);

        if ($existing instanceof OutboxEvent) {
            return $this->resolveDuplicate($connection, $existing, $event, $payload, $sourceKey);
        }

        try {
            $this->insert($connection, $sourceKey, $eventId, $payload, $mode);
        } catch (UniqueConstraintViolationException $exception) {
            // A locking read sees the winner even under MySQL's repeatable-read snapshot.
            $existing = OutboxEvent::query()
                ->where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();

            if (! $existing instanceof OutboxEvent) {
                throw $exception;
            }

            return $this->resolveDuplicate($connection, $existing, $event, $payload, $sourceKey, normalizeEventId: true);
        }

        $recorded = $this->findBySourceKey($sourceKey);

        if ($recorded instanceof OutboxEvent) {
            $this->afterCommit($connection, $recorded, $sourceKey, $mode);
        }

        return $recorded;
    }

    private function insert(
        Connection $connection,
        string $sourceKey,
        string $eventId,
        EventPayload $payload,
        DeliveryMode $mode,
    ): void {
        $connection->transaction(function () use ($connection, $sourceKey, $eventId, $payload, $mode): void {
            $timestamp = now();
            $status = match ($mode) {
                DeliveryMode::Shadow => OutboxStatus::Shadow,
                DeliveryMode::Live => OutboxStatus::Pending,
                DeliveryMode::Off => throw new RuntimeException('Off-mode Linkado events cannot be persisted.'),
            };

            $connection->table('linkado_outbox_events')->insert([
                'event_id' => $eventId,
                'source_key' => $sourceKey,
                'event_type' => $payload->type->value,
                'delivery_mode' => $mode->value,
                'status' => $status->value,
                'payload' => $payload->payload,
                'payload_sha256' => $payload->sha256,
                'attempt_count' => 0,
                'terminal_at' => $mode === DeliveryMode::Shadow ? $timestamp : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    private function findBySourceKey(string $sourceKey): ?OutboxEvent
    {
        return OutboxEvent::query()
            ->useWritePdo()
            ->where('source_key', $sourceKey)
            ->first();
    }

    private function afterCommit(
        Connection $connection,
        OutboxEvent $event,
        string $sourceKey,
        DeliveryMode $mode,
    ): void {
        $eventId = $this->eventId($event);
        $status = $event->getAttribute('status');

        if (! $status instanceof OutboxStatus) {
            throw new RuntimeException('The stored Linkado event status is invalid.');
        }

        $queue = $mode === DeliveryMode::Live
            ? $this->configuration->queue()
            : null;

        $connection->afterCommit(function () use ($eventId, $sourceKey, $status, $mode, $queue): void {
            $this->events->dispatch(new OutboxEventRecorded(
                eventId: $eventId,
                sourceKey: $sourceKey,
                status: $status,
            ));

            if ($mode !== DeliveryMode::Live) {
                return;
            }

            $job = new DeliverOutboxEvent($eventId);

            if ($queue !== null) {
                $job->onQueue($queue);
            }

            $lock = new UniqueLock($this->cache);
            $lockAcquired = false;

            try {
                $lockAcquired = $lock->acquire($job);

                if (! $lockAcquired) {
                    return;
                }

                $this->bus->dispatch($job);
            } catch (Throwable $exception) {
                if ($lockAcquired) {
                    try {
                        $lock->release($job);
                    } catch (Throwable) {
                    }
                }

                $this->events->dispatch(new OutboxDispatchFailed(
                    eventId: $eventId,
                    exceptionClass: $exception::class,
                ));
            }
        });
    }

    private function resolveDuplicate(
        Connection $connection,
        OutboxEvent $existing,
        EventData $event,
        EventPayload $payload,
        string $sourceKey,
        bool $normalizeEventId = false,
    ): OutboxEvent {
        if ($normalizeEventId && $event->event_id !== $this->eventId($existing)) {
            $payload = $this->payloadWithEventId($event, $this->eventId($existing));
        }

        if ($this->matches($existing, $payload)) {
            return $existing;
        }

        $connection->afterCommit(fn (): mixed => $this->events->dispatch(new OutboxPayloadConflictDetected(
            sourceKey: $sourceKey,
            existingEventId: $this->eventId($existing),
            attemptedEventId: $event->event_id,
        )));

        return $existing;
    }

    private function payloadWithEventId(EventData $event, string $eventId): EventPayload
    {
        $data = $event->toArray();
        $data['event_id'] = $eventId;
        unset($data['type']);

        return $this->codec->encode($event::from($data));
    }

    private function matches(OutboxEvent $existing, EventPayload $payload): bool
    {
        $eventType = $existing->getAttribute('event_type');
        $storedPayload = $existing->getAttribute('payload');
        $storedHash = $existing->getAttribute('payload_sha256');

        return $eventType instanceof EventType
            && $eventType === $payload->type
            && is_string($storedPayload)
            && hash_equals($storedPayload, $payload->payload)
            && is_string($storedHash)
            && hash_equals($storedHash, $payload->sha256);
    }

    private function eventId(OutboxEvent $event): string
    {
        $eventId = $event->getAttribute('event_id');

        if (! is_string($eventId)) {
            throw new RuntimeException('The stored Linkado event ID is invalid.');
        }

        return $eventId;
    }
}
