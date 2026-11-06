<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;

/**
 * The one place that knows how an EmailCommunicable declares "these addresses share one message".
 */
final class EmailGroup
{
    public const DECLARATION = 'getEmailGroup';

    public static function isGroup($communicable): bool
    {
        return $communicable instanceof EmailCommunicable
            && method_exists($communicable, self::DECLARATION);
    }

    /** @return string[] */
    public static function addressesOf($communicable): array
    {
        if (!static::isGroup($communicable)) {
            return [];
        }

        return collect($communicable->{self::DECLARATION}() ?? [])
            ->filter(fn ($address) => is_string($address) && trim($address) !== '')
            ->map(fn (string $address) => trim($address))
            ->values()
            ->all();
    }
}
