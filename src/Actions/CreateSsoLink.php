<?php

declare(strict_types=1);

namespace Linkado\Laravel\Actions;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Enums\DeliveryMode;
use Linkado\Laravel\Enums\LinkadoFeature;
use Linkado\Laravel\Support\EligibilityContext;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\PhpSdk\DataObjects\CreateSsoLinkData;
use Linkado\PhpSdk\DataObjects\SsoLinkData;
use Linkado\PhpSdk\LinkadoConnector;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

final readonly class CreateSsoLink
{
    /** @param Closure(): LinkadoConnector $connectorResolver */
    public function __construct(
        private LinkadoConfiguration $configuration,
        private DeterminesLinkadoEligibility $eligibility,
        private ResolvesLinkadoSsoUser $userResolver,
        private Closure $connectorResolver,
    ) {}

    public function handle(#[SensitiveParameter] Authenticatable $user, #[SensitiveParameter] Request $request): ?SsoLinkData
    {
        try {
            if ($this->configuration->mode() === DeliveryMode::Off
                || ! $this->configuration->featureEnabled(LinkadoFeature::Sso)
                || ! $this->eligibility->allows(
                    LinkadoFeature::Sso,
                    new EligibilityContext(user: $user, request: $request),
                )) {
                return null;
            }

            $resolvedUser = $this->userResolver->resolve($user);
            $link = ($this->connectorResolver)()->ssoLinks()->create(new CreateSsoLinkData(
                program_key: $this->configuration->requiredProgramKey(),
                external_user_id: $resolvedUser->externalUserId,
                email: $resolvedUser->email,
                email_verified: $resolvedUser->emailVerified,
                display_name: $resolvedUser->displayName,
                redirect_to: $resolvedUser->redirectTo,
            ));

            if ($this->validRedirectUrl($link->url)) {
                return $link;
            }
        } catch (Throwable) {
            // SDK and resolver exceptions can retain credentials, user data or one-time URLs.
        }

        throw new UnexpectedValueException('The Linkado SSO link could not be created safely.');
    }

    private function validRedirectUrl(#[SensitiveParameter] string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        $base = parse_url($this->configuration->requiredBaseUrl());

        return is_array($parts)
            && is_array($base)
            && isset($parts['scheme'], $parts['host'], $base['host'])
            && strtolower($parts['scheme']) === 'https'
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && strcasecmp($parts['host'], $base['host']) === 0
            && ($parts['port'] ?? 443) === ($base['port'] ?? 443);
    }
}
