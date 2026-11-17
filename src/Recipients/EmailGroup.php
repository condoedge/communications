<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;

/**
 * The one place that knows how an EmailCommunicable declares "these addresses share one message".
 */
final class EmailGroup
{
    public const DECLARATION = 'getEmailGroup';

    /** Static hook a recipient class may declare to load the addresses of many instances at once. */
    public const PRELOAD = 'preloadEmailGroups';

    /** One PRELOAD call per recipient class, so a send does not read addresses recipient by recipient. */
    public static function preload(iterable $communicables): void
    {
        collect($communicables)
            ->map(fn ($communicable) => RecipientKey::unwrap($communicable))
            ->filter(fn ($recipient) => is_object($recipient) && method_exists($recipient, self::PRELOAD))
            ->groupBy(fn ($recipient) => get_class($recipient))
            ->each(fn ($recipients, string $class) => secureCallCb(fn () => $class::{self::PRELOAD}($recipients->all())));
    }

    /**
     * Every address a message to this recipient may go to, grouped or not.
     *
     * @return string[]
     */
    public static function addressesToReach($communicable): array
    {
        if (static::isGroup($communicable)) {
            return static::addressesOf($communicable);
        }

        $address = $communicable instanceof EmailCommunicable ? $communicable->getEmail() : null;

        return is_string($address) && trim($address) !== '' ? [trim($address)] : [];
    }

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
