<?php

declare(strict_types=1);

namespace Linkado\Laravel\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\Enums\EventType;
use Throwable;

final class DiagnoseCommand extends Command
{
    /** @var string */
    protected $signature = 'linkado:diagnose
        {--json : Output the report as JSON}';

    /** @var string */
    protected $description = 'Diagnose Linkado delivery state without changing it.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LinkadoConfiguration $configuration,
        private readonly EventPayloadCodec $codec,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->report();

        if ($this->option('json') === true) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Category', 'Count', 'Command'],
            array_map(
                fn (string $category, int $count): array => [
                    $category,
                    (string) $count,
                    $this->commandFor($category, $report['commands']),
                ],
                array_keys($report['counts']),
                $report['counts'],
            ),
        );

        return self::SUCCESS;
    }

    /** @return array{counts: array{pending: int, stale: int, conflict: int, exhausted: int, corrupt: int}, commands: list<string>} */
    private function report(): array
    {
        $events = $this->database
            ->connection($this->configuration->connection())
            ->table('linkado_outbox_events');
        $staleBefore = CarbonImmutable::now()->subSeconds($this->configuration->deliveryClaimTimeoutSeconds());
        $pending = (clone $events)->where('status', OutboxStatus::Pending->value)->count();
        $stale = (clone $events)
            ->where('status', OutboxStatus::Delivering->value)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', $staleBefore)
            ->count();
        $conflict = (clone $events)
            ->where('status', OutboxStatus::Failed->value)
            ->where('last_error_code', 'http_409')
            ->count();
        $exhausted = (clone $events)
            ->where('status', OutboxStatus::Failed->value)
            ->where('last_error_code', '!=', 'http_409')
            ->where('attempt_count', '>=', $this->configuration->deliveryMaxAttempts())
            ->count();
        $corruptEvents = (clone $events)
            ->select(['event_id', 'event_type', 'payload', 'payload_sha256'])
            ->orderBy('id')
            ->get()
            ->filter(fn (object $event): bool => $this->isCorrupt($event));
        $corruptEventIds = $corruptEvents
            ->map(fn (object $event): mixed => get_object_vars($event)['event_id'] ?? null)
            ->filter(fn (mixed $eventId): bool => is_string($eventId))
            ->flip()
            ->all();
        $commands = [];

        if ($pending > 0 || $stale > 0) {
            $commands[] = 'php artisan linkado:recover';
        }

        foreach ((clone $events)
            ->select(['event_id'])
            ->where('status', OutboxStatus::Failed->value)
            ->where('last_error_code', '!=', 'http_409')
            ->where('attempt_count', '>=', $this->configuration->deliveryMaxAttempts())
            ->orderBy('event_id')
            ->get() as $event) {
            $values = get_object_vars($event);
            $eventId = $values['event_id'] ?? null;

            if (is_string($eventId) && ! array_key_exists($eventId, $corruptEventIds)) {
                $commands[] = 'php artisan linkado:retry '.$eventId.' --operator=operator --reason=manual-retry';
            }
        }

        return [
            'counts' => [
                'pending' => $pending,
                'stale' => $stale,
                'conflict' => $conflict,
                'exhausted' => $exhausted,
                'corrupt' => $corruptEvents->count(),
            ],
            'commands' => $commands,
        ];
    }

    /** @param list<string> $commands */
    private function commandFor(string $category, array $commands): string
    {
        if (($category === 'pending' || $category === 'stale') && in_array('php artisan linkado:recover', $commands, true)) {
            return 'php artisan linkado:recover';
        }

        if ($category !== 'exhausted') {
            return '';
        }

        foreach ($commands as $command) {
            if (str_starts_with($command, 'php artisan linkado:retry ')) {
                return $command;
            }
        }

        return '';
    }

    private function isCorrupt(object $event): bool
    {
        $values = get_object_vars($event);
        $eventId = $values['event_id'] ?? null;
        $eventType = $values['event_type'] ?? null;
        $payload = $values['payload'] ?? null;
        $sha256 = $values['payload_sha256'] ?? null;

        if (! is_string($eventId)
            || ! is_string($eventType)
            || ! is_string($payload)
            || ! is_string($sha256)) {
            return true;
        }

        $type = EventType::tryFrom($eventType);

        if ($type === null) {
            return true;
        }

        try {
            $this->codec->decodeStored($type, $eventId, $payload, $sha256);
        } catch (Throwable) {
            return true;
        }

        return false;
    }
}
