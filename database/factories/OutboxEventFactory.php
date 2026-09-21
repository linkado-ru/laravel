<?php

declare(strict_types=1);

namespace Linkado\Laravel\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Models\OutboxEvent;
use Linkado\PhpSdk\Enums\EventType;

/** @extends Factory<OutboxEvent> */
final class OutboxEventFactory extends Factory
{
    /** @var class-string<OutboxEvent> */
    protected $model = OutboxEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $eventId = strtolower((string) Str::ulid());
        $payload = json_encode([
            'event_id' => $eventId,
            'type' => EventType::CustomerCreated->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'event_id' => $eventId,
            'source_key' => 'factory:'.fake()->uuid(),
            'event_type' => EventType::CustomerCreated->value,
            'delivery_mode' => DeliveryMode::Live,
            'status' => OutboxStatus::Pending,
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $payload),
            'attempt_count' => 0,
        ];
    }
}
