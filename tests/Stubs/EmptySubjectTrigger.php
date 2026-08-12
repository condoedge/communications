<?php

namespace Condoedge\Communications\Tests\Stubs;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A trigger that DECLARES getSubject() but answers empty — the shape a translation-driven subject
 * takes when its lang key is missing (Coolecto declares getSubject() on 21 event classes and every
 * one of them returns a translation). Without this stub nothing reaches the falsy arm of the seeding
 * site's `?:`, so collapsing that expression to a plain method_exists ternary would ship a
 * subject-less email past a green suite.
 *
 * getName() varies with the active locale for the same reason as TitleOnlyTrigger: a fallback that
 * resolves once outside the per-locale closure shows up as two identical locales.
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
