<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support;

use Linkado\PhpSdk\Enums\SsoRedirect;

final readonly class ResolvedSsoUser
{
    public function __construct(
        public string $externalUserId,
        public ?string $email,
        public bool $emailVerified,
        public string $displayName,
        public SsoRedirect $redirectTo,
    ) {}
}
