<?php

namespace Condoedge\Communications\Services\TemplateResolution;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Illuminate\Support\Facades\Schema;
use Kompo\Auth\Teams\Contracts\TeamHierarchyInterface;

/**
 * Pure resolver — no caching. See EffectiveTemplateResolverContract.
 *
 * The model carries no auth global scope (BelongsToTeamTrait only adds a `forTeam` scope), and the
 * hierarchy walk uses raw recursive SQL, so the group query needs no `asSystemOperation()` wrapper.
 */
class EffectiveTemplateResolver implements EffectiveTemplateResolverContract
{
    public function __construct(
        protected TeamHierarchyInterface $hierarchy,
    ) {
    }

    public function resolve(string $trigger, int $teamId): EffectiveTemplateResolution
    {
        // getAncestorTeamIds is ROOT-first and INCLUDES self -> reverse() == closest-first [self, parent, .., root].
        $candidates = $this->hierarchy->getAncestorTeamIds($teamId)->reverse()->values();

        // orderByDesc so keyBy (which keeps the LAST row per key) settles on the lowest id, matching
        // what a ->first() lookup elsewhere would pick. Duplicate (team_id, trigger) rows are
        // possible since the uniqueness relax, and the admin editing one row while the send path
        // used another is how a Disable silently stops taking effect.
        $owned = CommunicationTemplateGroup::forTrigger($trigger)
            ->whereIn('team_id', $candidates->all())
            ->orderByDesc('id')
            ->get()
            ->keyBy('team_id');

        foreach ($candidates as $tid) {
            $tid = (int) $tid;
            $group = $owned->get($tid);

            if (!$group) {
                continue;
            }

            if ($group->disabled) {
                return new EffectiveTemplateResolution($trigger, $teamId, EffectiveTemplateSource::DISABLED, $tid, null);
            }

            $source = $tid === $teamId ? EffectiveTemplateSource::OWN : EffectiveTemplateSource::INHERITED;

            return new EffectiveTemplateResolution($trigger, $teamId, $source, $tid, $group);
        }

        return $this->fromSystemBaseline($trigger, $teamId);
    }

    public function resolveChannel(string $trigger, int $teamId, CommunicationType $type): ?CommunicationTemplate
    {
        $resolution = $this->resolve($trigger, $teamId);

        if (!$resolution->isSendable() || !$resolution->group) {
            return null;
        }

        return $resolution->group->findCommunicationTemplate($type->value);
    }

    public function forget(string $trigger, int $teamId): void
    {
        // Pure resolver memoizes nothing.
    }

    /**
     * The single system baseline group (team_id IS NULL) for the trigger, or NONE if none seeded.
     */
    protected function fromSystemBaseline(string $trigger, int $teamId): EffectiveTemplateResolution
    {
        $baseline = CommunicationTemplateGroup::forTrigger($trigger)
            ->whereNull('team_id')
            ->first();

        if (!$baseline) {
            return new EffectiveTemplateResolution($trigger, $teamId, EffectiveTemplateSource::NONE, null, null);
        }

        return new EffectiveTemplateResolution($trigger, $teamId, EffectiveTemplateSource::INHERITED, null, $baseline);
    }
}
