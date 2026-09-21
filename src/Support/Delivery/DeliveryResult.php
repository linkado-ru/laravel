<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Delivery;

use JsonException;
use Linkado\PhpSdk\DataObjects\EventResponseData;

final readonly class DeliveryResult
{
    public function __construct(
        public string $remoteEventId,
        public string $remoteStatus,
        public string $remoteWarnings,
    ) {}

    /** @throws JsonException */
    public static function fromResponse(EventResponseData $response): self
    {
        return new self(
            remoteEventId: $response->id,
            remoteStatus: $response->status->value,
            remoteWarnings: json_encode(
                $response->warnings,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
