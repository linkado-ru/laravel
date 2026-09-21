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
        Schema::connection(app(LinkadoConfiguration::class)->connection())->create('linkado_outbox_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_event_id')->constrained('linkado_outbox_events')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->ulid('claim_token');
            $table->string('outcome')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->string('remote_event_id')->nullable();
            $table->string('remote_status')->nullable();
            $table->text('remote_warnings')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('outbox_event_id');
            $table->unique(['outbox_event_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::connection(app(LinkadoConfiguration::class)->connection())->dropIfExists('linkado_outbox_attempts');
    }
};
