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
     * Triggers in `config('kompo-communications.triggers')` that have no CommunicationTemplateGroup yet.
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
     * Create the group + per-channel templates for one trigger. Idempotent — returns null
     * (and does nothing) if a group already exists for the trigger.
     *
     * @param class-string $trigger
     */
    public function seedForTrigger(string $trigger): ?CommunicationTemplateGroup;
}
