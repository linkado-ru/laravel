<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Health;

use InvalidArgumentException;

final readonly class HealthCheck
{
    public const string Healthy = 'healthy';

    public const string Warning = 'warning';

    public const string Failure = 'failure';

    public const string NotApplicable = 'not_applicable';

    public function __construct(
        public string $code,
        public string $status,
        public int $count = 0,
    ) {
        if (! in_array($status, [self::Healthy, self::Warning, self::Failure, self::NotApplicable], true)) {
            throw new InvalidArgumentException("Unknown Linkado health status [{$status}].");
        }

        if ($count < 0) {
            throw new InvalidArgumentException('A Linkado health check count cannot be negative.');
        }
    }

    /** @return array{code: string, status: string, count: int} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status,
            'count' => $this->count,
        ];
    }
}
