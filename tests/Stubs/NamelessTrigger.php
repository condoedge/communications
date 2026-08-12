<?php

namespace Condoedge\Communications\Tests\Stubs;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A LOADABLE trigger whose getName() answers empty. The only stub that reaches triggerName()'s
 * `?: '—'` arm: an unloadable or null trigger short-circuits on the class_exists/method_exists
 * guard and returns the other '—', so without this class that fallback has no coverage at all.
 */
class NamelessTrigger implements CommunicableEvent
{
    public static function getName(): string
    {
        return '';
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
