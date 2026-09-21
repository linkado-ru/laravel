<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Health;

final readonly class HealthReport
{
    /** @param list<HealthCheck> $checks */
    public function __construct(public array $checks) {}

    public function status(): string
    {
        if ($this->hasStatus(HealthCheck::Failure)) {
            return HealthCheck::Failure;
        }

        if ($this->hasStatus(HealthCheck::Warning)) {
            return HealthCheck::Warning;
        }

        return HealthCheck::Healthy;
    }

    public function exitCode(): int
    {
        return match ($this->status()) {
            HealthCheck::Failure => 2,
            HealthCheck::Warning => 1,
            default => 0,
        };
    }

    /** @return array{status: string, checks: list<array{code: string, status: string, count: int}>, counts: array{healthy: int, warning: int, failure: int, not_applicable: int}} */
    public function toArray(): array
    {
        $healthy = 0;
        $warning = 0;
        $failure = 0;
        $notApplicable = 0;

        foreach ($this->checks as $check) {
            if ($check->status === HealthCheck::Healthy) {
                $healthy++;
            } elseif ($check->status === HealthCheck::Warning) {
                $warning++;
            } elseif ($check->status === HealthCheck::Failure) {
                $failure++;
            } else {
                $notApplicable++;
            }
        }

        return [
            'status' => $this->status(),
            'checks' => array_map(static fn (HealthCheck $check): array => $check->toArray(), $this->checks),
            'counts' => [
                HealthCheck::Healthy => $healthy,
                HealthCheck::Warning => $warning,
                HealthCheck::Failure => $failure,
                HealthCheck::NotApplicable => $notApplicable,
            ],
        ];
    }

    private function hasStatus(string $status): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === $status) {
                return true;
            }
        }

        return false;
    }
}
