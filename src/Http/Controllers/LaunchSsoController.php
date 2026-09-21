<?php

declare(strict_types=1);

namespace Linkado\Laravel\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Linkado\Laravel\Actions\CreateSsoLink;
use Linkado\Laravel\Support\LinkadoConfiguration;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class LaunchSsoController
{
    public function __construct(
        private CreateSsoLink $createSsoLink,
        private LinkadoConfiguration $configuration,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof Authenticatable) {
                throw new LogicException('An authenticated user is required for Linkado SSO.');
            }

            $link = $this->createSsoLink->handle($user, $request);

            if ($link === null) {
                return $this->failureResponse();
            }

            return redirect()->away($link->url)->setContent('');
        } catch (Throwable) {
            return $this->failureResponse();
        }
    }

    private function failureResponse(): RedirectResponse
    {
        $this->logger->warning('Linkado SSO launch failed.');

        return redirect($this->configuration->ssoErrorRedirect())
            ->withErrors(['linkado' => trans('linkado::messages.sso_failed')]);
    }
}
