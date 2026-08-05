<?php

namespace Condoedge\Communications\Services\TemplateResolution;

use Condoedge\Communications\Models\CommunicationTemplateGroup;

/**
 * Immutable result of resolving a trigger for a team.
 *
 * @property-read string                          $trigger     the trigger class-string resolved
 * @property-read int                             $teamId      the team the resolution was requested for
 * @property-read EffectiveTemplateSource         $source      OWN / INHERITED / DISABLED / NONE
 * @property-read int|null                        $ownerTeamId team that owns the resolved group (null for system baseline or NONE/DISABLED-without-owner)
 * @property-read CommunicationTemplateGroup|null $group       the group that will actually send (null when not sendable)
 */
class EffectiveTemplateResolution
{
    public function __construct(
        public readonly string $trigger,
        public readonly int $teamId,
        public readonly EffectiveTemplateSource $source,
        public readonly ?int $ownerTeamId = null,
        public readonly ?CommunicationTemplateGroup $group = null,
    ) {
    }

    /**
     * A resolution sends iff a concrete group backs it and it is not disabled.
     * OWN / INHERITED (incl. inherited-from-system baseline) carry a group; DISABLED and NONE never do.
     */
    public function isSendable(): bool
    {
        return $this->group !== null
            && $this->source !== EffectiveTemplateSource::DISABLED
            && in_array($this->source, [EffectiveTemplateSource::OWN, EffectiveTemplateSource::INHERITED], true);
    }
}
