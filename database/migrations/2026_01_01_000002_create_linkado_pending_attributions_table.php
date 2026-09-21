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
        Schema::connection(app(LinkadoConfiguration::class)->connection())->create('linkado_pending_attributions', function (Blueprint $table): void {
            $table->id();
            $table->char('visitor_hash', 64)->unique();
            $table->string('click_id')->nullable();
            $table->string('referral_slug')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(app(LinkadoConfiguration::class)->connection())->dropIfExists('linkado_pending_attributions');
    }
};
