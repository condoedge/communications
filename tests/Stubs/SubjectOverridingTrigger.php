<?php

namespace Condoedge\Communications\Tests\Stubs;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Illuminate\Support\Collection;

/**
 * A trigger whose recipient-facing subject line is not the same sentence as its admin-facing
 * title. Both strings carry the active locale so a seeding bug that resolves either of them
 * outside the per-locale closure produces two identical locales rather than passing silently.
 *
 * getSubject() is NOT declared on CommunicableEvent and never will be — see the seeding site's
 * docblock in CommunicationTemplateGroup::createForTrigger().
 */
class SubjectOverridingTrigger implements CommunicableEvent
{
    public static function getName(): string
    {
        return 'name-' . app()->getLocale();
    }

    public static function getSubject(): string
    {
        return 'subject-' . app()->getLocale();
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
