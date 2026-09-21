<?php

declare(strict_types=1);

namespace Linkado\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Linkado\Laravel\Support\LinkadoConfiguration;

final class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'linkado:prune
        {--json : Output the result as JSON}';

    /** @var string */
    protected $description = 'Delete expired Linkado pending attribution.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LinkadoConfiguration $configuration,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pruned = $this->database
            ->connection($this->configuration->connection())
            ->table('linkado_pending_attributions')
            ->where('expires_at', '<=', now())
            ->delete();

        if ($this->option('json') === true) {
            $this->line(json_encode(['pruned' => $pruned], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $record = $pruned === 1 ? 'record' : 'records';
        $this->info("Pruned {$pruned} expired Linkado attribution {$record}.");

        return self::SUCCESS;
    }
}
