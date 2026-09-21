<?php

declare(strict_types=1);

namespace Linkado\Laravel\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Linkado\Laravel\Support\ResolvedSsoUser;

interface ResolvesLinkadoSsoUser
{
    public function resolve(Authenticatable $user): ResolvedSsoUser;
}
