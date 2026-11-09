<?php

namespace Condoedge\Communications\Tests\Stubs;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\EventsHandling\Contracts\DatabaseCommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A trigger that restricts the notification button handlers, so seeding its DATABASE template must
 * pre-select one.
 */
class ButtonHandlerTrigger implements CommunicableEvent, DatabaseCommunicableEvent
{
    public const HANDLER = 'App\\Notifications\\OpenTheThingHandler';

    public static function getName(): string
    {
        return 'name-' . app()->getLocale();
    }

    public static function validNotificationButtonHandlers($context = []): array
    {
        return [self::HANDLER];
    }

    public static function getValidRoutes(): array
    {
        return [];
    }

    public function getParams(): array
    {
        return [];
    }

    public function getCommunicables(): Collection|array
    {
        return collect();
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        return null;
    }
}
