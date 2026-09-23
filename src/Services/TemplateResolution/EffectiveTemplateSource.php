<?php

namespace Condoedge\Communications\Services\TemplateResolution;

/**
 * The state of a (trigger, team) resolution after walking the hierarchy.
 *
 * - OWN:       the team itself configured an enabled group for the trigger.
 * - INHERITED: the nearest configured ancestor (or the system baseline) provides an enabled group.
 * - DISABLED:  the nearest configured team on the path marked the trigger disabled (suppresses subtree).
 * - NONE:      nothing on the path and no system baseline — trigger is not configured anywhere.
 */
enum EffectiveTemplateSource: string
{
    case OWN = 'own';
    case INHERITED = 'inherited';
    case DISABLED = 'disabled';
    case NONE = 'none';
}
