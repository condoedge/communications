<?php

namespace Condoedge\Communications\Models;

use Condoedge\Utils\Models\Model;
use Illuminate\Support\Collection;

class CommunicationTemplateGroup extends Model
{
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
            ->get();

        $communications->each->notify($communicables, $params);
    } 

    public static function deleteOldVoids()
    {
        return self::voids()->whereDate('created_at', '<', now()->subDay())
            ->delete();
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

    public function deletable()
    {
        return $this->team_id == auth()->user()->team_id;
    }

    public function delete()
    {
        $this->communicationTemplates->each->delete();

        return parent::delete();
    }
}