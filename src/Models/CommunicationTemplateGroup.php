<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Triggers\ManualTrigger;
use Condoedge\Utils\Models\Model;
use Illuminate\Support\Collection;

class CommunicationTemplateGroup extends Model
{
    use \Kompo\Auth\Models\Teams\BelongsToTeamTrait;

    // RELATIONSHIPS
    public function communicationTemplates()
    {
        return $this->hasMany(CommunicationTemplate::class, 'template_group_id');
    }

    // CALCULATED FIELDS
    public static function getTriggers()
    {
        return config('kompo-communications.triggers');
    }

    public function findCommunicationTemplate($type)
    {
        return $this->communicationTemplates()->where('type', $type)->first();
    }

    public static function triggerName(?string $trigger): string
    {
        if ($trigger && class_exists($trigger) && method_exists($trigger, 'getName')) {
            try {
                return $trigger::getName() ?: '—';
            } catch (\Throwable $e) {
                return '—';
            }
        }

        return '—';
    }

    // SCOPES
    public function scopeForTrigger($query, $trigger)
    {
        return $query->where('trigger', $trigger);
    }

    public function scopeHasValid($query)
    {
        return $query->whereHas('communicationTemplates', fn($q) => $q->isValid());
    }

    public function scopeVoids($query)
    {
        return $query->doesntHave('communicationTemplates');
    }
    
    // ACTIONS
    public function notify(array|Collection $communicables, $type = null, $params = []) 
    {
        $communications = $this->communicationTemplates()
            ->when($type, fn($q) => $q->where('type', $type))
            ->isValid()
            ->get();

        $communications->each->notify($communicables, $params);
    } 

    public static function deleteOldVoids()
    {
        return self::voids()->whereDate('created_at', '<', now()->subDay())
            ->delete();
    }


    /**
     * @method createManualTemp
     * 
     * @description To send a just one case communication. It could be used more than once, 
     * but it'll be hidden in the list and always be used to trigger manually
     */
    public static function createManualTemp()
    {
        $trigger = ManualTrigger::class;

        $communicationTemplateGroup = new static;
        $communicationTemplateGroup->trigger = $trigger;
        $communicationTemplateGroup->title = $trigger::getName();
        $communicationTemplateGroup->direct_usage = true;
        $communicationTemplateGroup->save();

        return $communicationTemplateGroup;
    }

    public static function createForTrigger($trigger)
    {
        $communicationTemplate = new static;
        $communicationTemplate->trigger = $trigger;
        $communicationTemplate->title = $trigger::getName();
        $communicationTemplate->save();

        collect(CommunicationType::cases())->each(function ($type) use ($communicationTemplate, $trigger) {
            $className = substr(strrchr($trigger, '\\'), 1);
            $sluggedName = \Str::slug(\Str::snake($className));
            $viewName = "stubs/communication-templates/default-{$sluggedName}-{$type->value}";

            $content = collect(array_keys(config('kompo.locales')))->mapWithKeys(function($locale) use ($viewName) { 
                if (!file_exists(resource_path('views/' . $viewName . '-' . $locale . ".blade.php"))) return [$locale => ''];

                return [$locale => view($viewName .'-'. $locale)->render()];
            })->filter();

            if (!$content->count()) {
                return null;
            }

            $type->handler(null)->save($communicationTemplate->id, [
                'subject' => collect(array_keys(config('kompo.locales')))->mapWithKeys(fn($locale) => [$locale => $communicationTemplate->title]),
                'content' => $content->toArray(),
            ]);
        });

        return $communicationTemplate;
    }

    /**
     * Deep-clone this group as a private override owned by $teamId (copy & edit / disable flows).
     *
     * Replicates the group (stamping team_id, source_group_id = this->id, disabled = false) plus
     * every CommunicationTemplate and its NotificationTemplate sidecar (DB-channel button handler),
     * preserving the translatable subject/content/custom_button_text JSON. The clone is persisted
     * before returning so the editor opens on real, saved rows (Kompo JSON-on-edit trap).
     */
    public function copyForTeam(int $teamId): self
    {
        // unique(team_id, trigger): a team owns at most one group per trigger. Fail fast with a
        // clear message instead of letting the DB throw an opaque integrity violation.
        $existing = static::query()->where('team_id', $teamId)
            ->where('trigger', $this->trigger)->exists();

        if ($existing) {
            throw new \RuntimeException(
                "Team {$teamId} already owns a communication template for trigger [{$this->trigger}]."
            );
        }

        // A partial clone (group saved, templates half-written) would leave a broken override that
        // the resolver would still pick up. Wrap the whole deep clone so it is all-or-nothing.
        return \DB::transaction(function () use ($teamId) {
            $clone = $this->replicate();
            $clone->team_id = $teamId;
            $clone->source_group_id = $this->id;
            $clone->disabled = false;
            $clone->save();

            foreach ($this->communicationTemplates as $template) {
                $templateClone = $template->replicate();
                $templateClone->template_group_id = $clone->id;
                $templateClone->save();

                NotificationTemplate::where('communication_id', $template->id)->get()
                    ->each(function ($notificationTemplate) use ($templateClone) {
                        $notificationClone = $notificationTemplate->replicate();
                        $notificationClone->communication_id = $templateClone->id;
                        $notificationClone->save();
                    });
            }

            return $clone->refresh();
        });
    }

    public function deletable()
    {
        // System baseline (team_id NULL) is never deletable by a team admin.
        return $this->team_id && $this->team_id == currentTeamId();
    }

    public function delete()
    {
        $this->communicationTemplates->each->delete();

        return parent::delete();
    }
}