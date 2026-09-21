<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Exceptions\MissingSsoUserResolver;
use Linkado\Laravel\Facades\Linkado;
use Linkado\Laravel\Support\EligibilityContext;

it('allows every feature through the default eligibility contract', function () {
    $eligibility = app(DeterminesLinkadoEligibility::class);

    expect($eligibility->allows(LinkadoFeature::Tracking, new EligibilityContext))->toBeTrue()
        ->and(Linkado::allows(LinkadoFeature::Tracking, new EligibilityContext))->toBeTrue();
});

it('requires applications to bind an SSO user resolver', function () {
    $resolver = app(ResolvesLinkadoSsoUser::class);

    expect(fn () => $resolver->resolve(new GenericUser(['id' => 'merchant-42'])))
        ->toThrow(MissingSsoUserResolver::class);
});
