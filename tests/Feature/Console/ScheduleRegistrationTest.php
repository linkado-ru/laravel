<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('registers package maintenance commands with safe schedules', function () {
    $events = collect(app(Schedule::class)->events());

    $recover = $events->first(
        fn (Event $event): bool => str_contains((string) $event->command, 'linkado:recover'),
    );
    $prune = $events->first(
        fn (Event $event): bool => str_contains((string) $event->command, 'linkado:prune'),
    );

    expect($recover)
        ->not->toBeNull()
        ->command->toContain('linkado:recover')
        ->expression->toBe('*/5 * * * *')
        ->withoutOverlapping->toBeTrue()
        ->and($prune)
        ->not->toBeNull()
        ->command->toContain('linkado:prune')
        ->expression->toBe('0 0 * * *')
        ->withoutOverlapping->toBeTrue();
});
