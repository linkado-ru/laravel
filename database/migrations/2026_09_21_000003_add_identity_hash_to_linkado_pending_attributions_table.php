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
        Schema::connection(app(LinkadoConfiguration::class)->connection())->table('linkado_pending_attributions', function (Blueprint $table): void {
            $table->string('identity_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(app(LinkadoConfiguration::class)->connection())->table('linkado_pending_attributions', function (Blueprint $table): void {
            $table->dropColumn('identity_hash');
        });
    }
};
