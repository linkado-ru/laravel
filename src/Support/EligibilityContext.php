<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Linkado\PhpSdk\DataObjects\EventData;

final readonly class EligibilityContext
{
    public function __construct(
        public ?Authenticatable $user = null,
        public ?Request $request = null,
        public ?EventData $event = null,
    ) {}
}
