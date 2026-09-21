<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Linkado\Laravel\Support\Attribution\AttributionIdentifiers;
use Linkado\Laravel\Support\LinkadoConfiguration;
use RuntimeException;

final readonly class CapturePendingAttribution
{
    public function __construct(
        private DatabaseManager $database,
        private LinkadoConfiguration $configuration,
    ) {}

    public function handle(string $visitorId, mixed $clickId, mixed $referralSlug): void
    {
        if (! AttributionIdentifiers::validCandidates($clickId, $referralSlug)) {
            return;
        }

        $clickId = AttributionIdentifiers::click($clickId) ? $clickId : null;
        $referralSlug = AttributionIdentifiers::referral($referralSlug) ? $referralSlug : null;

        if ($clickId === null && $referralSlug === null) {
            return;
        }

        $connection = $this->database->connection($this->configuration->connection());
        $visitorHash = hash('sha256', $visitorId);

        // Only a transaction owned by capture may be replayed after a deadlock.
        $connection->transaction(
            fn () => $this->capture($connection, $visitorHash, $clickId, $referralSlug),
            attempts: $connection->transactionLevel() === 0 ? 3 : 1,
        );
    }

    private function capture(Connection $connection, string $visitorHash, ?string $clickId, ?string $referralSlug): void
    {
        $query = $connection->table('linkado_pending_attributions')->where('visitor_hash', $visitorHash);
        $row = $query->lockForUpdate()->first();
        $created = false;

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
                $created = true;
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

        $expiresAt = $row?->expires_at;

        if ($row !== null && ! is_string($expiresAt) && ! $expiresAt instanceof DateTimeInterface) {
            throw new RuntimeException('The stored attribution expiry is invalid.');
        }

        if ($created || ($row !== null && CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo($now))) {
            $query->update([
                'click_id' => $clickId,
                'referral_slug' => $clickId === null ? $referralSlug : null,
                'captured_at' => $now,
                'expires_at' => $now->addSeconds($this->configuration->trackingTtlSeconds()),
                'consumed_at' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($row !== null && $row->consumed_at === null && $row->click_id === null && $clickId !== null) {
            $query->update([
                'click_id' => $clickId,
                'referral_slug' => null,
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
