<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Delivery;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

final class FailureClassifier
{
    public function isRetryable(FatalRequestException|RequestException $failure): bool
    {
        if ($failure instanceof FatalRequestException) {
            return true;
        }

        $status = $failure->getStatus();

        return $status === 408
            || $status === 429
            || ($status >= 500 && $status <= 599);
    }

    public function httpStatus(FatalRequestException|RequestException $failure): ?int
    {
        return $failure instanceof RequestException
            ? $failure->getStatus()
            : null;
    }

    public function retryAfter(FatalRequestException|RequestException $failure): ?string
    {
        if (! $failure instanceof RequestException) {
            return null;
        }

        $header = $failure->getResponse()->header('Retry-After');

        if (is_array($header)) {
            $header = $header[0] ?? null;
        }

        if (! is_string($header)) {
            return null;
        }

        $header = trim($header);

        return $header === '' ? null : $header;
    }

    public function code(FatalRequestException|RequestException $failure): string
    {
        $status = $this->httpStatus($failure);

        return $status === null ? 'request_failed' : 'http_'.$status;
    }

    /** @return class-string<FatalRequestException|RequestException> */
    public function exceptionClass(FatalRequestException|RequestException $failure): string
    {
        return $failure instanceof FatalRequestException
            ? FatalRequestException::class
            : RequestException::class;
    }
}
