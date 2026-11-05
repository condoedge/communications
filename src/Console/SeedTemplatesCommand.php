<?php

namespace Condoedge\Communications\Console;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\TemplateSeeding\TemplateSeedingServiceContract;
use Illuminate\Console\Command;

class SeedTemplatesCommand extends Command
{
    protected $signature = 'communications:seed-templates
        {--add-channel= : Also add this channel (CommunicationType value, e.g. 5 for push) to the existing groups that lack it, from its stubs.}';

    protected $description = 'Create default CommunicationTemplateGroup + per-channel templates for every registered communication trigger. Idempotent.';

    public function handle(TemplateSeedingServiceContract $service): int
    {
        if ($this->option('add-channel') !== null) {
            $type = CommunicationType::tryFrom((int) $this->option('add-channel'));

            if (!$type) {
                $this->error('Unknown channel: ' . $this->option('add-channel'));

                return self::FAILURE;
            }

            $groups = $service->seedChannelIntoExistingGroups($type);

            $this->info("Channel {$type->name} added to {$groups->count()} existing group(s):");
            foreach ($groups as $group) {
                $this->line("  ✓ {$group->trigger}" . ($group->team_id ? " (team {$group->team_id})" : ' (baseline)'));
            }
            $this->newLine();
        }

        $allTriggers = collect(CommunicationTemplateGroup::getTriggers());
        $missing = $service->getMissingTriggers();

        $this->info("Triggers registered: {$allTriggers->count()}.");
        $this->info("Missing groups: {$missing->count()}.");

        if ($missing->isEmpty()) {
            $this->line('Nothing to do.');
            return self::SUCCESS;
        }

        $created = $service->seedAll();

        $this->newLine();
        $this->info("Created {$created->count()} group(s):");
        foreach ($created as $group) {
            $this->line("  ✓ {$group->trigger}");
        }

        $stillMissing = $service->getMissingTriggers();
        if ($stillMissing->isNotEmpty()) {
            $this->newLine();
            $this->warn('Some triggers were not seeded (no stub blade matched any channel):');
            foreach ($stillMissing as $trigger) {
                $this->line("  ✗ {$trigger}");
            }
        }

        return self::SUCCESS;
    }
}
