<?php

declare(strict_types=1);

namespace Linkado\Laravel\Support\Events;

use InvalidArgumentException;
use JsonException;
use Linkado\PhpSdk\DataObjects\CustomerCreatedEventData;
use Linkado\PhpSdk\DataObjects\EventData;
use Linkado\PhpSdk\DataObjects\LeadCreatedEventData;
use Linkado\PhpSdk\DataObjects\PaymentRefundedEventData;
use Linkado\PhpSdk\DataObjects\PaymentSucceededEventData;
use Linkado\PhpSdk\DataObjects\SubscriptionCancelledEventData;
use Linkado\PhpSdk\DataObjects\SubscriptionRenewedEventData;
use Linkado\PhpSdk\Enums\EventType;
use TypeError;
use UnexpectedValueException;
use ValueError;

final class EventPayloadCodec
{
    /** @var list<class-string<EventData>> */
    private const array DTO_CLASSES = [
        CustomerCreatedEventData::class,
        LeadCreatedEventData::class,
        PaymentSucceededEventData::class,
        PaymentRefundedEventData::class,
        SubscriptionRenewedEventData::class,
        SubscriptionCancelledEventData::class,
    ];

    /** @throws JsonException */
    public function encode(EventData $event): EventPayload
    {
        $payload = $this->encodeArray($event->toArray());

        return new EventPayload(
            dtoClass: $event::class,
            type: $event->type(),
            payload: $payload,
            sha256: hash('sha256', $payload),
        );
    }

    /** @throws JsonException */
    public function decode(string $dtoClass, string $payload): EventData
    {
        if (! in_array($dtoClass, self::DTO_CLASSES, true)) {
            throw new InvalidArgumentException('Unsupported Linkado event DTO class.');
        }

        $data = $this->decodeObject($payload);

        unset($data['type']);

        /** @var class-string<EventData> $dtoClass */
        $event = $dtoClass::from($data);
        $roundTrip = $this->encode($event);

        if (! hash_equals(hash('sha256', $payload), $roundTrip->sha256)
            || ! hash_equals($payload, $roundTrip->payload)) {
            throw new UnexpectedValueException('The Linkado event payload is not canonical.');
        }

        return $event;
    }

    public function decodeStored(
        EventType $type,
        string $eventId,
        string $payload,
        string $sha256,
    ): EventData {
        if (! hash_equals(hash('sha256', $payload), $sha256)) {
            throw new UnexpectedValueException('The stored Linkado event payload hash is invalid.');
        }

        try {
            $event = $this->decode($this->dtoClass($type), $payload);
        } catch (InvalidArgumentException|JsonException|TypeError|ValueError $exception) {
            throw new UnexpectedValueException(
                'The stored Linkado event payload is invalid.',
                previous: $exception,
            );
        }

        if ($event->type() !== $type || ! hash_equals($eventId, $event->event_id)) {
            throw new UnexpectedValueException('The stored Linkado event identity is invalid.');
        }

        return $event;
    }

    /** @return class-string<EventData> */
    private function dtoClass(EventType $type): string
    {
        return match ($type) {
            EventType::CustomerCreated => CustomerCreatedEventData::class,
            EventType::LeadCreated => LeadCreatedEventData::class,
            EventType::PaymentSucceeded => PaymentSucceededEventData::class,
            EventType::PaymentRefunded => PaymentRefundedEventData::class,
            EventType::SubscriptionRenewed => SubscriptionRenewedEventData::class,
            EventType::SubscriptionCancelled => SubscriptionCancelledEventData::class,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    private function encodeArray(array $data): string
    {
        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeObject(string $payload): array
    {
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || array_is_list($data)) {
            throw new UnexpectedValueException('The Linkado event payload must be a JSON object.');
        }

        $object = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('The Linkado event payload must use string field names.');
            }

            $object[$key] = $value;
        }

        return $object;
    }
}
