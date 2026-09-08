<?php

namespace Condoedge\Communications\Services\TemplateResolution;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;

/**
 * Single source of truth for "which communication template applies to this team for this trigger".
 *
 * Walks the team hierarchy child -> ancestors (closest wins); a closer `disabled` row beats a
 * farther enabled one; `team_id IS NULL` is the universal system baseline / fallback.
 *
 * Reused by the runtime dispatch service, the inscription events, and the admin Templates UI, so
 * every path makes the exact same decision.
 */
interface EffectiveTemplateResolverContract
{
    /**
     * Resolve the effective template group for a trigger as seen from $teamId.
     *
     * @param class-string $trigger
     */
    public function resolve(string $trigger, int $teamId): EffectiveTemplateResolution;

    /**
     * The per-channel template (for UI preview) of the resolved group, or null when the resolution
     * is not sendable or the group has no template for that channel.
     *
     * @param class-string $trigger
     */
    public function resolveChannel(string $trigger, int $teamId, CommunicationType $type): ?CommunicationTemplate;

    /**
     * Drop any memoized resolution for (trigger, team). The pure resolver is a no-op; a cache
     * decorator evicts its entry so the next resolve() re-reads the template groups.
     *
     * @param class-string $trigger
     */
    public function forget(string $trigger, int $teamId): void;
}
