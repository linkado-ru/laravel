<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Linkado\Laravel\Support\Delivery\FailureClassifier;
use Linkado\Laravel\Support\Delivery\RetrySchedule;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-21 12:00:00 UTC');

    config()->set('linkado.delivery.max_attempts', 8);
    config()->set('linkado.delivery.base_delay_seconds', 60);
    config()->set('linkado.delivery.max_delay_seconds', 21600);
    config()->set('linkado.delivery.retry_window_seconds', 86400);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('classifies every HTTP status family according to the delivery policy', function (int $status, bool $retryable): void {
    $exception = Mockery::mock(RequestException::class);
    $exception->shouldReceive('getStatus')->andReturn($status);

    $classifier = new FailureClassifier;

    expect($classifier->isRetryable($exception))->toBe($retryable)
        ->and($classifier->httpStatus($exception))->toBe($status)
        ->and($classifier->code($exception))->toBe('http_'.$status);
})->with([
    'bad request' => [400, false],
    'unauthorized' => [401, false],
    'forbidden' => [403, false],
    'not found' => [404, false],
    'request timeout' => [408, true],
    'conflict' => [409, false],
    'other client error' => [418, false],
    'unprocessable entity' => [422, false],
    'too many requests' => [429, true],
    'internal server error' => [500, true],
    'other server error' => [599, true],
    'outside server error family' => [600, false],
]);

it('classifies fatal request failures as retryable without exposing their message', function (): void {
    $exception = Mockery::mock(FatalRequestException::class);
    $classifier = new FailureClassifier;

    expect($classifier->isRetryable($exception))->toBeTrue()
        ->and($classifier->httpStatus($exception))->toBeNull()
        ->and($classifier->code($exception))->toBe('request_failed')
        ->and($classifier->exceptionClass($exception))->toBe(FatalRequestException::class);
});

it('uses capped exponential delays', function (int $attempt, int $expectedSeconds): void {
    $now = CarbonImmutable::now();
    $scheduled = p16RetrySchedule(false)->nextAttemptAt(
        attemptNumber: $attempt,
        createdAt: $now,
        now: $now,
    );

    expect($scheduled?->equalTo($now->addSeconds($expectedSeconds)))->toBeTrue();
})->with([
    'first attempt' => [1, 60],
    'second attempt' => [2, 120],
    'seventh attempt' => [7, 3840],
]);

it('applies injectable jitter at both bounds', function (bool $upperBound, int $expectedSeconds): void {
    $now = CarbonImmutable::now();
    $scheduled = p16RetrySchedule($upperBound)->nextAttemptAt(
        attemptNumber: 1,
        createdAt: $now,
        now: $now,
    );

    expect($scheduled?->equalTo($now->addSeconds($expectedSeconds)))->toBeTrue();
})->with([
    'lower bound' => [false, 60],
    'upper bound' => [true, 120],
]);

it('never exceeds the configured six hour maximum after jitter', function (): void {
    config()->set('linkado.delivery.max_attempts', 20);
    $now = CarbonImmutable::now();
    $scheduled = p16RetrySchedule(true)->nextAttemptAt(
        attemptNumber: 10,
        createdAt: $now,
        now: $now,
    );

    expect($scheduled?->equalTo($now->addSeconds(21600)))->toBeTrue();
});

it('parses Retry-After seconds and HTTP dates', function (string $header, ?int $expectedSeconds): void {
    expect(p16RetrySchedule(false)->retryAfterSeconds($header, CarbonImmutable::now()))
        ->toBe($expectedSeconds);
})->with([
    'delta seconds' => ['300', 300],
    'HTTP date' => ['Mon, 21 Sep 2026 12:05:00 GMT', 300],
    'past HTTP date' => ['Mon, 21 Sep 2026 11:59:00 GMT', 0],
    'negative seconds' => ['-1', null],
    'informal date' => ['next Tuesday', null],
    'empty header' => ['', null],
]);

it('lets a valid Retry-After override exponential backoff', function (): void {
    $now = CarbonImmutable::now();
    $scheduled = p16RetrySchedule(true)->nextAttemptAt(
        attemptNumber: 3,
        createdAt: $now,
        now: $now,
        retryAfterSeconds: 17,
    );

    expect($scheduled?->equalTo($now->addSeconds(17)))->toBeTrue();
});

it('does not schedule attempt eight', function (): void {
    $now = CarbonImmutable::now();

    expect(p16RetrySchedule(false)->nextAttemptAt(
        attemptNumber: 8,
        createdAt: $now,
        now: $now,
    ))->toBeNull();
});

it('enforces the retry window boundary', function (int $eventAge, bool $scheduled): void {
    $now = CarbonImmutable::now();
    $nextAttemptAt = p16RetrySchedule(false)->nextAttemptAt(
        attemptNumber: 1,
        createdAt: $now->subSeconds($eventAge),
        now: $now,
    );

    expect($nextAttemptAt !== null)->toBe($scheduled);
})->with([
    'exactly twenty four hours' => [86340, true],
    'one second beyond twenty four hours' => [86341, false],
]);

it('does not replace a Retry-After beyond the retry window with an earlier retry', function (): void {
    $now = CarbonImmutable::now();

    expect(p16RetrySchedule(false)->nextAttemptAt(
        attemptNumber: 1,
        createdAt: $now->subSeconds(100),
        now: $now,
        retryAfterSeconds: 86400,
    ))->toBeNull();
});

function p16RetrySchedule(bool $upperBound): RetrySchedule
{
    return new RetrySchedule(
        configuration: app(LinkadoConfiguration::class),
        jitter: static fn (int $minimum, int $maximum): int => $upperBound ? $maximum : $minimum,
    );
}

it('terminates oversized Retry-After without overflowing dates or portable storage', function (string $header): void {
    $now = CarbonImmutable::now();
    $schedule = p16RetrySchedule(false);
    $seconds = $schedule->retryAfterSeconds($header, $now);

    expect($schedule->nextAttemptAt(1, $now, $now, $seconds))->toBeNull()
        ->and($seconds)->toBe(2147483647);
})->with(['2147483648', '4294967296', '9223372036854775807', '999999999999999999999999999999', 'Fri, 31 Dec 9999 23:59:59 GMT']);
