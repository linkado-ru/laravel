<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Events\OutboxEventRecovered;
use Linkado\Laravel\Jobs\DeliverOutboxEvent;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\Laravel\Support\LinkadoConfiguration;
use RuntimeException;
use Throwable;

final readonly class RecoverOutboxEvents
{
    private const int CHUNK_SIZE = 100;

    public function __construct(
        private LinkadoConfiguration $configuration,
        private BusDispatcher $bus,
        private CacheRepository $cache,
        private Dispatcher $events,
    ) {}

    /** @return array{recovered: int} */
    public function handle(): array
    {
        if ($this->configuration->mode() !== DeliveryMode::Live) {
            return ['recovered' => 0];
        }

        $recovered = 0;
        $staleBefore = CarbonImmutable::now()->subSeconds(
            $this->configuration->deliveryClaimTimeoutSeconds(),
        );

        OutboxEvent::query()
            ->select(['id'])
            ->where(function (Builder $query) use ($staleBefore): void {
                $query
                    ->where(function (Builder $pending): void {
                        $pending
                            ->where('status', OutboxStatus::Pending->value)
                            ->where(function (Builder $due): void {
                                $due
                                    ->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', CarbonImmutable::now());
                            });
                    })
                    ->orWhere(function (Builder $delivering) use ($staleBefore): void {
                        $delivering
                            ->where('status', OutboxStatus::Delivering->value)
                            ->whereNotNull('claimed_at')
                            ->where('claimed_at', '<=', $staleBefore);
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function (Collection $events) use (&$recovered): void {
                foreach ($events as $event) {
                    if ($this->recover($this->modelId($event))) {
                        $recovered++;
                    }
                }
            });

        return ['recovered' => $recovered];
    }

    private function recover(int $id): bool
    {
        $connection = DB::connection($this->configuration->connection());
        $event = $connection->transaction(function () use ($id): ?OutboxEvent {
            $event = OutboxEvent::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($event === null
                || $this->configuration->mode() !== DeliveryMode::Live
                || ! $this->isRecoverable($event, CarbonImmutable::now())) {
                return null;
            }

            return $event;
        });

        if (! $event instanceof OutboxEvent || ! $this->dispatch($event->event_id)) {
            return false;
        }

        $this->events->dispatch(new OutboxEventRecovered(
            eventId: $event->event_id,
            sourceKey: $event->source_key,
            status: $event->status,
            recoveredAt: CarbonImmutable::now(),
        ));

        return true;
    }

    private function modelId(OutboxEvent $event): int
    {
        $id = $event->getKey();

        if (! is_int($id)) {
            throw new RuntimeException('The Linkado outbox row ID is invalid.');
        }

        return $id;
    }

    private function isRecoverable(OutboxEvent $event, CarbonImmutable $now): bool
    {
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

    private function dispatch(string $eventId): bool
    {
        $job = new DeliverOutboxEvent($eventId);
        $queue = $this->configuration->queue();

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
