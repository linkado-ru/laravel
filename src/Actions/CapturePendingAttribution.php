<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Linkado\Laravel\Support\Attribution\AttributionIdentifiers;
use Linkado\Laravel\Support\Attribution\IdentityLock;
use Linkado\Laravel\Support\Attribution\PreparedAttribution;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\DataObjects\TrackingClickData;

final readonly class CapturePendingAttribution
{
    public function __construct(
        private DatabaseManager $database,
        private LinkadoConfiguration $configuration,
        private IdentityLock $identityLock,
        private PreparedAttribution $preparedAttribution,
    ) {}

    public function handle(Request $request, string $visitorId): void
    {
        $verified = $this->preparedAttribution->prepare($request);

        if ($verified === null) {
            return;
        }

        $connection = $this->database->connection($this->configuration->connection());
        $visitorHash = hash('sha256', $visitorId);

        // Only a transaction owned by capture may be replayed after a deadlock.
        $connection->transaction(
            fn () => $this->identityLock->run($request, function (?string $identityHash) use ($connection, $visitorHash, $verified): void {
                $this->capture($connection, $visitorHash, $identityHash, $verified);
            }),
            attempts: $connection->transactionLevel() === 0 ? 3 : 1,
        );
    }

    private function capture(Connection $connection, string $visitorHash, ?string $identityHash, TrackingClickData $verified): void
    {
        $query = $connection->table('linkado_pending_attributions')->where('visitor_hash', $visitorHash);
        $row = $query->lockForUpdate()->first();

        if ($row === null) {
            try {
                // PostgreSQL must roll back the failed insert's savepoint before reading the winner.
                $connection->transaction(function () use ($connection, $visitorHash): void {
                    $connection->table('linkado_pending_attributions')->insert([
                        'visitor_hash' => $visitorHash,
                        'captured_at' => now(),
                        'expires_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isVisitorConflict($connection, $exception)) {
                    throw $exception;
                }

                $row = $query->lockForUpdate()->first();

                if ($row === null) {
                    throw $exception;
                }
            }
        }

        // Read the clock only after acquiring the existing row or inserting our locked row.
        $now = CarbonImmutable::now();

        if ($verified->expires_at <= $now || ($row !== null && ($row->identity_hash !== $identityHash || $row->consumed_at !== null))) {
            return;
        }

        if ($row === null || $row->program_key !== $verified->program_key || AttributionIdentifiers::visitOrder($row->visit_order) === null || AttributionIdentifiers::visitOrder($row->visit_order) < $verified->visit_order) {
            $query->update([
                'program_key' => $verified->program_key,
                'identity_hash' => $identityHash,
                'click_id' => $verified->click_id,
                'referral_slug' => null,
                'visit_order' => $verified->visit_order,
                'captured_at' => $verified->created_at,
                'expires_at' => $verified->expires_at,
                'consumed_at' => null,
                'updated_at' => $now,
            ]);
        }
    }

    private function isVisitorConflict(Connection $connection, UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->errorInfo[2] ?? null;

        if (! is_string($message)) {
            return false;
        }

        $table = $connection->getTablePrefix().'linkado_pending_attributions';

        if ($connection->getDriverName() === 'sqlite') {
            return $message === 'UNIQUE constraint failed: '.$table.'.visitor_hash';
        }

        // Inspect the actual name: prefixes are normalized by Laravel and long names
        // may be truncated by PostgreSQL. Only the visitor key may resolve a race.
        foreach ($connection->getSchemaBuilder()->getIndexes('linkado_pending_attributions') as $index) {
            if (! $index['unique'] || $index['columns'] !== ['visitor_hash']) {
                continue;
            }

            $matches = match ($connection->getDriverName()) {
                'mysql', 'mariadb' => preg_match("/for key '(?:(?i:".preg_quote($table, '/').')\\.)?'.preg_quote($index['name'], '/')."'/", $message) === 1,
                'pgsql' => str_contains($message, '"'.$index['name'].'"'),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
