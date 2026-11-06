<?php

namespace Condoedge\Communications\Services\TemplateSeeding;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Illuminate\Support\Collection;

/**
 * Owns default-template creation for every registered communication trigger.
 *
 * Used from:
 *   - Artisan command `communications:seed-templates`
 *   - The (currently commented) `Create default templates` button on the Communications admin page
 *
 * Both entry points must produce identical state — that's the reason this lives in a service
 * rather than as a static method on `CommunicationTemplateGroup`.
 */
interface TemplateSeedingServiceContract
{
    /**
     * Triggers in `config('kompo-communications.triggers')` that have no system-baseline group
     * (team_id NULL) yet — regardless of any team-owned overrides they may already carry.
     *
     * @return Collection<int, class-string>
     */
    public function getMissingTriggers(): Collection;

    /**
     * Create groups for every missing trigger.
     *
     * @return Collection<int, CommunicationTemplateGroup>
     */
    public function seedAll(): Collection;

    /**
     * Create the baseline group + per-channel templates for one trigger. Idempotent and
     * disable-safe — returns null (and does nothing) if a baseline already exists for the trigger,
     * including a disabled one.
     *
     * @param class-string $trigger
     */
    public function seedForTrigger(string $trigger): ?CommunicationTemplateGroup;

    /**
     * Add one channel, from its stubs, to the groups that already exist without it — the
     * baseline and the team-owned copies alike. For a channel added after the environment
     * was seeded, which seedAll() never revisits. A template of that channel that exists,
     * even soft-deleted by an admin, is left alone. One-off manual temps are skipped.
     *
     * @return Collection<int, CommunicationTemplateGroup> the groups that received the channel
     */
    public function seedChannelIntoExistingGroups(\Condoedge\Communications\Models\CommunicationType $type): Collection;
}
