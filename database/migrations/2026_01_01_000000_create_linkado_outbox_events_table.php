<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Linkado\Laravel\Support\LinkadoConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(app(LinkadoConfiguration::class)->connection())->create('linkado_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->string('source_key')->unique();
            $table->string('event_type')->index();
            $table->string('delivery_mode')->index();
            $table->string('status')->index();
            $table->text('payload');
            $table->char('payload_sha256', 64);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->ulid('claim_token')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->string('remote_event_id')->nullable();
            $table->string('remote_status')->nullable();
            $table->text('remote_warnings')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('last_error_class')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(app(LinkadoConfiguration::class)->connection())->dropIfExists('linkado_outbox_events');
    }
};
