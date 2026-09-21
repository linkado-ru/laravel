<?php

declare(strict_types=1);

namespace Linkado\Laravel\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Models\OutboxAttempt;

/** @extends Factory<OutboxAttempt> */
final class OutboxAttemptFactory extends Factory
{
    /** @var class-string<OutboxAttempt> */
    protected $model = OutboxAttempt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'outbox_event_id' => OutboxEventFactory::new(),
            'number' => 1,
            'claim_token' => strtolower((string) Str::ulid()),
            'outcome' => AttemptOutcome::Delivered,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
