<?php

namespace Condoedge\Communications\Services\Grouping;

use Illuminate\Support\Collection;

/** Default binding: no grouping, so the admin Templates tab hides the group column + filter. */
class NullTriggerGroupResolver implements TriggerGroupResolverContract
{
    public function groupFor(string $trigger): ?TriggerGroup
    {
        return null;
    }

    public function options(): Collection
    {
        return collect();
    }
}
