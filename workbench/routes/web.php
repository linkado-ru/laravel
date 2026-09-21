<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Linkado\Laravel\Facades\Linkado;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;

Route::view('/', 'linkado-demo')
    ->middleware('linkado.attribution')
    ->name('linkado.demo');

Route::post('/linkado/events/customer-created', function (Request $request): RedirectResponse {
    $input = $request->validate([
        'source_key' => ['required', 'string', 'max:255'],
        'external_customer_id' => ['required', 'string', 'max:255'],
    ]);
    $programKey = config('linkado.program_key');

    abort_unless(is_string($programKey) && $programKey !== '', 503, 'Configure LINKADO_PROGRAM_KEY first.');

    $event = DB::connection(config('linkado.connection'))->transaction(
        fn () => Linkado::record(
            sourceKey: $input['source_key'],
            eventFactory: fn (string $eventId): CustomerCreatedEventData => new CustomerCreatedEventData(
                event_id: $eventId,
                program_key: $programKey,
                occurred_at: now(),
                external_customer_id: $input['external_customer_id'],
            ),
        ),
    );

    return to_route('linkado.demo')->with(
        'status',
        $event === null
            ? 'No event was recorded. Check the mode, feature flag, and eligibility policy.'
            : "Recorded Linkado event {$event->event_id} with status {$event->status->value}.",
    );
})->name('linkado.demo.customer-created');
