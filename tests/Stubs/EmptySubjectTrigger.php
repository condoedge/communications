<?php

namespace Condoedge\Communications\Tests\Stubs;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A trigger that declares getSubject() but answers empty.
 */
class EmptySubjectTrigger implements CommunicableEvent
{
    public static function getName(): string
    {
        return 'name-' . app()->getLocale();
    }

    public static function getSubject(): string
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
