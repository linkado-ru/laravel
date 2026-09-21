<?php

declare(strict_types=1);

namespace Linkado\Laravel\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Linkado\Laravel\Actions\RetryOutboxEvent;

final class RetryCommand extends Command
{
    /** @var string */
    protected $signature = 'linkado:retry
        {event : Linkado event ID}
        {--operator= : Operator identifier for the audit trail}
        {--reason= : Reason for the manual retry}
        {--json : Output the result as JSON}';

    /** @var string */
    protected $description = 'Retry one failed Linkado event with an audit record.';

    public function __construct(private readonly RetryOutboxEvent $retry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = (string) $this->argument('event');
        $operator = $this->option('operator');
        $reason = $this->option('reason');

        if (! is_string($operator) || trim($operator) === '') {
            return $this->refuse($eventId, RetryOutboxEvent::OPERATOR_REQUIRED);
        }

        if (! is_string($reason) || trim($reason) === '') {
            return $this->refuse($eventId, RetryOutboxEvent::REASON_REQUIRED);
        }

        try {
            $event = $this->retry->handle($eventId, $operator, $reason);
        } catch (DomainException $exception) {
            return $this->refuse($eventId, $exception->getMessage());
        }

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'event_id' => $event->event_id,
                'result' => 'retried',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Event', 'Result'], [[$event->event_id, 'retried']]);

        return self::SUCCESS;
    }

    private function refuse(string $eventId, string $reason): int
    {
        if ($this->option('json') === true) {
            $this->line(json_encode([
                'event_id' => $eventId,
                'result' => 'refused',
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error((string) trans('linkado::messages.retry_'.$reason));

        return self::FAILURE;
    }
}
