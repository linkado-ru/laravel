<?php

declare(strict_types=1);

namespace Linkado\Laravel\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Linkado\Laravel\Models\PendingAttribution;

/** @extends Factory<PendingAttribution> */
final class PendingAttributionFactory extends Factory
{
    /** @var class-string<PendingAttribution> */
    protected $model = PendingAttribution::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'visitor_hash' => hash('sha256', fake()->uuid()),
            'click_id' => fake()->uuid(),
            'referral_slug' => null,
            'captured_at' => now(),
            'expires_at' => now()->addDays(30),
            'consumed_at' => null,
        ];
    }
}
