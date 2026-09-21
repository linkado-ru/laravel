<?php

declare(strict_types=1);

namespace Linkado\Laravel\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Linkado\Laravel\Database\Factories\OutboxAttemptFactory;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Support\LinkadoConfiguration;

/**
 * @property int $id
 * @property int $outbox_event_id
 * @property int<1, max> $number
 * @property string $claim_token
 * @property AttemptOutcome|null $outcome
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[Guarded([])]
#[UseFactory(OutboxAttemptFactory::class)]
final class OutboxAttempt extends Model
{
    /** @use HasFactory<OutboxAttemptFactory> */
    use HasFactory;

    protected $table = 'linkado_outbox_attempts';

    public function getConnectionName(): ?string
    {
        return app(LinkadoConfiguration::class)->connection() ?? parent::getConnectionName();
    }

    /** @return BelongsTo<OutboxEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outbox_event_id' => 'integer',
            'number' => 'integer',
            'outcome' => AttemptOutcome::class,
            'http_status' => 'integer',
            'retry_after_seconds' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
