<?php

declare(strict_types=1);

namespace Linkado\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Support\Health\HealthCheck;
use Linkado\Laravel\Support\Health\HealthReport;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Throwable;

final class HealthCommand extends Command
{
    /** @var string */
    protected $signature = 'linkado:health
        {--json : Output the report as JSON}';

    /** @var string */
    protected $description = 'Report the health of the Linkado integration.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LinkadoConfiguration $configuration,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->report();

        if ($this->option('json') === true) {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Code', 'Status', 'Count'],
                array_map(
                    static fn (HealthCheck $check): array => [$check->code, $check->status, (string) $check->count],
                    $report->checks,
                ),
            );
        }

        return $report->exitCode();
    }

    private function report(): HealthReport
    {
        try {
            $mode = $this->configuration->mode();
        } catch (Throwable) {
            return new HealthReport([
                $this->failure('configuration'),
                $this->notApplicable('database'),
                $this->notApplicable('migrations'),
                $this->notApplicable('pending_lag'),
                $this->notApplicable('stale_claims'),
                $this->notApplicable('permanent_failures'),
            ]);
        }

        if ($mode === DeliveryMode::Off) {
            return new HealthReport([
                $this->healthy('configuration'),
                $this->notApplicable('database'),
                $this->notApplicable('migrations'),
                $this->notApplicable('pending_lag'),
                $this->notApplicable('stale_claims'),
                $this->notApplicable('permanent_failures'),
            ]);
        }

        $configuration = $this->configurationCheck($mode);
        $connection = $this->connectionCheck();

        if ($connection === null) {
            return new HealthReport([
                $configuration,
                $this->failure('database'),
                $this->notApplicable('migrations'),
                $this->notApplicable('pending_lag'),
                $this->notApplicable('stale_claims'),
                $this->notApplicable('permanent_failures'),
            ]);
        }

        $migrations = $this->migrationCheck($connection);

        if ($migrations->status === HealthCheck::Failure) {
            return new HealthReport([
                $configuration,
                $this->healthy('database'),
                $migrations,
                $this->notApplicable('pending_lag'),
                $this->notApplicable('stale_claims'),
                $this->notApplicable('permanent_failures'),
            ]);
        }

        return new HealthReport([
            $configuration,
            $this->healthy('database'),
            $migrations,
            $this->pendingLagCheck(),
            $this->staleClaimsCheck(),
            $this->permanentFailuresCheck(),
        ]);
    }

    private function configurationCheck(DeliveryMode $mode): HealthCheck
    {
        if ($mode !== DeliveryMode::Live) {
            return $this->healthy('configuration');
        }

        try {
            $this->configuration->requiredToken();
            $baseUrl = $this->configuration->requiredBaseUrl();
            $parts = parse_url($baseUrl);

            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! isset($parts['host'])) {
                return $this->failure('configuration');
            }
        } catch (Throwable) {
            return $this->failure('configuration');
        }

        return $this->healthy('configuration');
    }

    private function connectionCheck(): ?Builder
    {
        try {
            $connection = $this->database->connection($this->configuration->connection());
            $connection->getPdo();

            return $connection->getSchemaBuilder();
        } catch (Throwable) {
            return null;
        }
    }

    private function migrationCheck(Builder $schema): HealthCheck
    {
        $missing = 0;

        foreach (['linkado_outbox_events', 'linkado_outbox_attempts', 'linkado_pending_attributions'] as $table) {
            if (! $schema->hasTable($table)) {
                $missing++;
            }
        }

        return $missing === 0
            ? $this->healthy('migrations')
            : $this->failure('migrations', $missing);
    }

    private function pendingLagCheck(): HealthCheck
    {
        $query = $this->database
            ->connection($this->configuration->connection())
            ->table('linkado_outbox_events')
            ->where('status', OutboxStatus::Pending->value);

        $failed = (clone $query)->where('created_at', '<=', now()->subMinutes(60))->count();

        if ($failed > 0) {
            return $this->failure('pending_lag', $failed);
        }

        $warning = $query->where('created_at', '<=', now()->subMinutes(15))->count();

        return $warning > 0
            ? $this->warning('pending_lag', $warning)
            : $this->healthy('pending_lag');
    }

    private function staleClaimsCheck(): HealthCheck
    {
        $count = $this->database
            ->connection($this->configuration->connection())
            ->table('linkado_outbox_events')
            ->where('status', OutboxStatus::Delivering->value)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', now()->subSeconds($this->configuration->deliveryClaimTimeoutSeconds()))
            ->count();

        return $count > 0
            ? $this->warning('stale_claims', $count)
            : $this->healthy('stale_claims');
    }

    private function permanentFailuresCheck(): HealthCheck
    {
        $count = $this->database
            ->connection($this->configuration->connection())
            ->table('linkado_outbox_events')
            ->where('status', OutboxStatus::Failed->value)
            ->whereNotNull('terminal_at')
            ->count();

        return $count > 0
            ? $this->failure('permanent_failures', $count)
            : $this->healthy('permanent_failures');
    }

    private function healthy(string $code): HealthCheck
    {
        return new HealthCheck($code, HealthCheck::Healthy);
    }

    private function warning(string $code, int $count): HealthCheck
    {
        return new HealthCheck($code, HealthCheck::Warning, $count);
    }

    private function failure(string $code, int $count = 1): HealthCheck
    {
        return new HealthCheck($code, HealthCheck::Failure, $count);
    }

    private function notApplicable(string $code): HealthCheck
    {
        return new HealthCheck($code, HealthCheck::NotApplicable);
    }
}
