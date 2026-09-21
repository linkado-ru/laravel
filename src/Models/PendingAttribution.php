<?php

declare(strict_types=1);

namespace Linkado\Laravel\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Linkado\Laravel\Database\Factories\PendingAttributionFactory;
use Linkado\Laravel\Support\LinkadoConfiguration;

/** @property int $id */
#[Guarded([])]
#[UseFactory(PendingAttributionFactory::class)]
final class PendingAttribution extends Model
{
    /** @use HasFactory<PendingAttributionFactory> */
    use HasFactory;

    protected $table = 'linkado_pending_attributions';

    public function getConnectionName(): ?string
    {
        return app(LinkadoConfiguration::class)->connection() ?? parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
