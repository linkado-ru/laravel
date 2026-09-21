<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Delivery;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Linkado\Laravel\Support\LinkadoConfiguration;
use UnexpectedValueException;

final readonly class RetrySchedule
{
    /** @var Closure(int, int): int */
    private Closure $jitter;

    /** @param null|Closure(int, int): int $jitter */
    public function __construct(
        private LinkadoConfiguration $configuration,
        ?Closure $jitter = null,
    ) {
        $this->jitter = $jitter ?? random_int(...);
    }

    public function nextAttemptAt(
        int $attemptNumber,
        CarbonImmutable $createdAt,
        CarbonImmutable $now,
        ?int $retryAfterSeconds = null,
    ): ?CarbonImmutable {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('The attempt number must be positive.');
        }

        if ($attemptNumber >= $this->configuration->deliveryMaxAttempts()) {
            return null;
        }

        $delay = $retryAfterSeconds ?? $this->exponentialDelay($attemptNumber);
        $deadline = $createdAt->addSeconds($this->configuration->deliveryRetryWindowSeconds());

        if ($delay < 0 || $delay > $now->diffInSeconds($deadline, false)) {
            return null;
        }

        return $now->addSeconds($delay);
    }

    public function retryAfterSeconds(string $header, CarbonImmutable $now): ?int
    {
        $header = trim($header);

        if (preg_match('/\A[0-9]+\z/D', $header) === 1) {
            $normalized = ltrim($header, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            // PostgreSQL's integer column is signed; saturate oversized remote values.
            $maximum = '2147483647';

            if (strlen($normalized) > strlen($maximum)
                || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
                return 2147483647;
            }

            return (int) $normalized;
        }

        $date = DateTimeImmutable::createFromFormat(
            DATE_RFC7231,
            $header,
            new DateTimeZone('GMT'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format(DATE_RFC7231) !== $header) {
            return null;
        }

        return min(2147483647, max(0, $date->getTimestamp() - $now->getTimestamp()));
    }

    /** @param int<1, max> $attemptNumber */
    private function exponentialDelay(int $attemptNumber): int
    {
        $base = $this->configuration->deliveryBaseDelaySeconds();
        $maximum = $this->configuration->deliveryMaxDelaySeconds();
        $delay = min($base, $maximum);

        for ($number = 1; $number < $attemptNumber && $delay < $maximum; $number++) {
            $delay = $delay > intdiv($maximum, 2)
                ? $maximum
                : min($maximum, $delay * 2);
        }

        $jitterMaximum = min($base, $maximum - $delay);
        $jitter = ($this->jitter)(0, $jitterMaximum);

        if ($jitter < 0 || $jitter > $jitterMaximum) {
            throw new UnexpectedValueException('Retry jitter is outside the requested bounds.');
        }

        return $delay + $jitter;
    }
}
