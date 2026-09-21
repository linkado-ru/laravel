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

    public function handle(Authenticatable $user, Request $request): ?SsoLinkData
    {
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

        if (! $this->validRedirectUrl($link->url)) {
            throw new UnexpectedValueException('The Linkado SSO redirect URL is invalid.');
        }

        return $link;
    }

    private function validRedirectUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($this->configuration->requiredBaseUrl(), PHP_URL_HOST);

        return is_string($scheme)
            && strtolower($scheme) === 'https'
            && is_string($host)
            && is_string($baseHost)
            && strcasecmp($host, $baseHost) === 0;
    }
}
