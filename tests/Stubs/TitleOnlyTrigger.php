<?php

namespace Condoedge\Communications\Tests\Stubs;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A trigger that implements CommunicableEvent and nothing else.
 */
class TitleOnlyTrigger implements CommunicableEvent
{
    public static function getName(): string
    {
        return 'name-' . app()->getLocale();
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
