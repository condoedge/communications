<?php

namespace Condoedge\Communications\Services\Grouping;

use Illuminate\Support\Collection;

/**
 * Maps triggers to logical groups for the admin Templates tab. The default (Null) resolver
 * returns no group, which collapses the group column + filter; a host app binds an adapter
 * over its own domain grouping.
 */
interface TriggerGroupResolverContract
{
    public function groupFor(string $trigger): ?TriggerGroup;

    /** Filter options as value => label. Empty => no group filter/column. */
    public function options(): Collection;
}
