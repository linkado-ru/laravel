<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Enums\AttemptOutcome;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Enums\OutboxStatus;
use Linkado\Laravel\Facades\Linkado as LinkadoFacade;
use Linkado\Laravel\Linkado;
use Linkado\Laravel\Support\Attribution\ConsumedAttribution;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\ResolvedSsoUser;
use Linkado\PhpSdk\DataObjects\EventData;
use Linkado\PhpSdk\Enums\EventType;
use Linkado\PhpSdk\Enums\SsoRedirect;

it('defines the locked eligibility contract', function () {
    $method = new ReflectionMethod(DeterminesLinkadoEligibility::class, 'allows');

    expect($method->getReturnType()?->getName())->toBe('bool')
        ->and($method->getParameters())->toHaveCount(2)
        ->and($method->getParameters()[0]->getType()?->getName())->toBe(LinkadoFeature::class)
        ->and($method->getParameters()[1]->getType()?->getName())->toBe(EligibilityContext::class);
});

it('defines the locked SSO user resolver contract', function () {
    $method = new ReflectionMethod(ResolvesLinkadoSsoUser::class, 'resolve');

    expect($method->getReturnType()?->getName())->toBe(ResolvedSsoUser::class)
        ->and($method->getParameters())->toHaveCount(1)
        ->and($method->getParameters()[0]->getType()?->getName())->toBe(Authenticatable::class);
});

it('exposes the locked public enum values', function () {
    expect(LinkadoFeature::cases())->toBe([
        LinkadoFeature::Sso,
        LinkadoFeature::Tracking,
        LinkadoFeature::CustomerEvents,
        LinkadoFeature::BillingEvents,
        LinkadoFeature::RefundEvents,
    ])
        ->and(OutboxStatus::cases())->toBe([
            OutboxStatus::Pending,
            OutboxStatus::Shadow,
            OutboxStatus::Delivering,
            OutboxStatus::Delivered,
            OutboxStatus::Failed,
        ])
        ->and(AttemptOutcome::cases())->toBe([
            AttemptOutcome::Delivered,
            AttemptOutcome::RetryScheduled,
            AttemptOutcome::Failed,
            AttemptOutcome::PackageDisabled,
            AttemptOutcome::ManuallyRetried,
        ]);
});

it('constructs immutable public value objects', function () {
    $request = Request::create('/linkado');
    $event = testEventData();
    $context = new EligibilityContext(user: null, request: $request, event: $event);
    $resolvedUser = new ResolvedSsoUser(
        externalUserId: 'merchant-42',
        email: 'merchant@example.test',
        emailVerified: true,
        displayName: 'Merchant',
        redirectTo: SsoRedirect::AffiliatePortal,
    );
    $attribution = new ConsumedAttribution(clickId: 'click-123', referralSlug: 'partner');

    expect((new ReflectionClass(EligibilityContext::class))->isReadOnly())->toBeTrue()
        ->and($context->request)->toBe($request)
        ->and($context->event)->toBe($event)
        ->and((new ReflectionClass(ResolvedSsoUser::class))->isReadOnly())->toBeTrue()
        ->and($resolvedUser->externalUserId)->toBe('merchant-42')
        ->and($resolvedUser->email)->toBe('merchant@example.test')
        ->and($resolvedUser->emailVerified)->toBeTrue()
        ->and($resolvedUser->displayName)->toBe('Merchant')
        ->and($resolvedUser->redirectTo)->toBe(SsoRedirect::AffiliatePortal)
        ->and((new ReflectionClass(ConsumedAttribution::class))->isReadOnly())->toBeTrue()
        ->and($attribution->clickId)->toBe('click-123')
        ->and($attribution->referralSlug)->toBe('partner');
});

it('keeps the facade accessor pointed at the Linkado service', function () {
    $accessor = new ReflectionMethod(LinkadoFacade::class, 'getFacadeAccessor');

    expect($accessor->invoke(null))->toBe(Linkado::class);
});

function testEventData(): EventData
{
    return new class('event-01', 'program-key', '2026-09-21T00:00:00Z', 'customer-01') extends EventData
    {
        public function type(): EventType
        {
            return EventType::CustomerCreated;
        }

        protected function eventPayload(): array
        {
            return [];
        }
    };
}
