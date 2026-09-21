<?php

declare(strict_types=1);

namespace Linkado\Laravel\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Linkado\Laravel\Database\Factories\OutboxEventFactory;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\Enums\EventType;

/**
 * @property string $event_id
 * @property int $id
 * @property EventType $event_type
 * @property DeliveryMode $delivery_mode
 * @property OutboxStatus $status
 * @property string $payload
 * @property string $payload_sha256
 * @property int<0, max> $attempt_count
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $claimed_at
 * @property string|null $claim_token
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $terminal_at
 * @property CarbonImmutable $created_at
 */
#[Guarded(['id', 'event_id', 'source_key', 'event_type', 'delivery_mode', 'payload', 'payload_sha256'])]
#[UseFactory(OutboxEventFactory::class)]
final class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory;

    protected $table = 'linkado_outbox_events';

    public function getConnectionName(): ?string
    {
        return app(LinkadoConfiguration::class)->connection() ?? parent::getConnectionName();
    }

    /** @return HasMany<OutboxAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(OutboxAttempt::class, 'outbox_event_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => EventType::class,
            'delivery_mode' => DeliveryMode::class,
            'status' => OutboxStatus::class,
            'attempt_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
