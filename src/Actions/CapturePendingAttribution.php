<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Linkado\Laravel\Support\LinkadoConfiguration;

final readonly class CapturePendingAttribution
{
    public function __construct(
        private DatabaseManager $database,
        private LinkadoConfiguration $configuration,
    ) {}

    public function handle(string $visitorId, mixed $clickId, mixed $referralSlug): void
    {
        $clickId = $this->normalize($clickId);
        $referralSlug = $this->normalize($referralSlug);

        if ($clickId === null && $referralSlug === null) {
            return;
        }

        $now = now();
        $visitorHash = hash('sha256', $visitorId);
        $values = [
            'visitor_hash' => $visitorHash,
            'click_id' => $clickId,
            'referral_slug' => $clickId === null ? $referralSlug : null,
            'captured_at' => $now,
            'expires_at' => $now->copy()->addSeconds($this->configuration->trackingTtlSeconds()),
            'consumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $connection = $this->database->connection($this->configuration->connection());

        if ($clickId !== null) {
            $connection->table('linkado_pending_attributions')->upsert(
                [$values],
                ['visitor_hash'],
                ['click_id', 'referral_slug', 'captured_at', 'expires_at', 'consumed_at', 'updated_at'],
            );

            return;
        }

        $connection->table('linkado_pending_attributions')->insertOrIgnore($values);

        $connection->table('linkado_pending_attributions')
            ->where('visitor_hash', $visitorHash)
            ->update([
                'captured_at' => $now,
                'expires_at' => $values['expires_at'],
                'consumed_at' => null,
                'updated_at' => $now,
            ]);

        $connection->table('linkado_pending_attributions')
            ->where('visitor_hash', $visitorHash)
            ->whereNull('click_id')
            ->update(['referral_slug' => $referralSlug]);
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }
}
