<?php

declare(strict_types=1);

namespace Linkado\Laravel\Contracts;

use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;

interface DeterminesLinkadoEligibility
{
    public function allows(LinkadoFeature $feature, EligibilityContext $context): bool;
}
