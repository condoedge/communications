<?php

namespace Condoedge\Communications\Tests\Stubs;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A trigger that implements CommunicableEvent and NOTHING else — the exact shape of all 34
 * triggers registered by the SISC host today. Its whole job is to prove that a trigger which
 * has never heard of getSubject() keeps seeding the subject it seeded before that seam existed.
 *
 * getName() deliberately varies with the active locale so that a seeding bug which resolves the
 * name once, outside the per-locale closure, shows up as two identical locales instead of
 * passing silently.
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
