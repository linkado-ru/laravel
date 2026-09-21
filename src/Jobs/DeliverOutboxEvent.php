<?php

declare(strict_types=1);

namespace Linkado\Laravel\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Linkado\Laravel\Actions\ClaimOutboxEvent;
use Linkado\Laravel\Actions\DeliverClaimedOutboxEvent;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Throwable;

final class DeliverOutboxEvent implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $eventId) {}

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function uniqueFor(): int
    {
        return app(LinkadoConfiguration::class)->deliveryClaimTimeoutSeconds();
    }

    public function handle(
        ClaimOutboxEvent $claimOutboxEvent,
        DeliverClaimedOutboxEvent $deliverClaimedOutboxEvent,
    ): void {
        $claimed = $claimOutboxEvent->claim($this->eventId);

        if ($claimed === null) {
            return;
        }

        $nextAttemptAt = $deliverClaimedOutboxEvent->handle($claimed);

        if ($nextAttemptAt !== null) {
            $this->dispatchRetry($nextAttemptAt);
        }
    }

    private function dispatchRetry(CarbonImmutable $nextAttemptAt): void
    {
        $configuration = app(LinkadoConfiguration::class);
        $job = (new self($this->eventId))->delay($nextAttemptAt);
        $queue = $configuration->queue();

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        $lock = new UniqueLock(app(CacheRepository::class));
        $lockAcquired = false;

        try {
            $lockAcquired = $lock->acquire($job);

            if (! $lockAcquired) {
                return;
            }

            app(BusDispatcher::class)->dispatch($job);
        } catch (Throwable) {
            if ($lockAcquired) {
                try {
                    $lock->release($job);
                } catch (Throwable) {
                }
            }
        }
    }
}
