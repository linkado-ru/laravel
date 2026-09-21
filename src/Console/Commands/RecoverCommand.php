<?php

declare(strict_types=1);

namespace Linkado\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Linkado\Laravel\Actions\RecoverOutboxEvents;

final class RecoverCommand extends Command
{
    /** @var string */
    protected $signature = 'linkado:recover
        {--json : Output the result as JSON}';

    /** @var string */
    protected $description = 'Queue due and stalled Linkado delivery for recovery.';

    public function __construct(private readonly RecoverOutboxEvents $recovery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->recovery->handle();

        if ($this->option('json') === true) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], [['Recovered', (string) $result['recovered']]]);

        return self::SUCCESS;
    }
}
