<?php

declare(strict_types=1);

namespace Linkado\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Linkado\Laravel\Linkado
 */
class Linkado extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Linkado\Laravel\Linkado::class;
    }
}
