<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Attribution;

use Illuminate\Support\Str;

/** @internal */
final class AttributionIdentifiers
{
    public static function absent(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /** @phpstan-assert-if-true string $value */
    public static function click(mixed $value): bool
    {
        return is_string($value) && Str::isUlid($value);
    }

    /** @phpstan-assert-if-true string $value */
    public static function referral(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 100
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) === 1;
    }

    public static function validCandidates(mixed $click, mixed $referral): bool
    {
        return (self::absent($click) || self::click($click))
            && (self::absent($referral) || self::referral($referral));
    }
}
