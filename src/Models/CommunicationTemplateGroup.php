<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Triggers\ManualTrigger;
use Condoedge\Utils\Models\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

    /**
     * A team's own reusable manual communications — the exact set the manual screens may act on.
     * The model has no team global scope, so this is the only thing keeping one team out of
     * another's groups. $teamId is never null here, so the system baseline (team_id NULL) and the
     * one-off direct_usage temps both stay out of reach.
     */
    public function scopeManualForTeam($query, $teamId)
    {
        return $query->forTrigger(ManualTrigger::class)
            ->where('team_id', $teamId)
            ->where(fn ($q) => $q->whereNull('direct_usage')->orWhere('direct_usage', false));
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

        if ($communications->isEmpty()) {
            Log::warning('Communication group has no sendable channel', [
                'template_group_id' => $this->id,
                'trigger' => $this->trigger,
                'type' => $type,
            ]);

            return;
        }

        // CommunicationTemplate::notify contains its own delivery failures, so anything reaching
        // here means that channel wrote nothing and sent nothing. Keep going so one broken channel
        // never abandons the rest of the group.
        $delivered = 0;
        $lastError = null;

        foreach ($communications as $communication) {
            try {
                $sending = $communication->notify($communicables, $params);

                // "Did not throw" is not the same as "reached someone": the handler contains every
                // per-recipient failure, so a totally undelivered channel returns normally. Only the
                // recorded outcome can answer whether a retry would duplicate anything.
                if ($sending && in_array($sending->status, [CommunicationSendingStatus::SENT, CommunicationSendingStatus::PARTIAL], true)) {
                    $delivered++;
                }
            } catch (\Throwable $e) {
                $lastError = $e;

                Log::error('Communication channel failed before sending', [
                    'communication_id' => $communication->id,
                    'channel' => $communication->type?->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Nothing reached anyone and something broke: surface it so the queue retries. Safe
        // precisely because no channel delivered — a retry cannot duplicate. If even one channel
        // got through, swallow it instead; retrying would re-send to everyone it already reached.
        if ($lastError && $delivered === 0) {
            throw $lastError;
        }
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
    public static function createManualTemp(?int $teamId = null)
    {
        $trigger = ManualTrigger::class;

        $communicationTemplateGroup = new static;
        $communicationTemplateGroup->trigger = $trigger;
        $communicationTemplateGroup->title = $trigger::getName();
        $communicationTemplateGroup->direct_usage = true;
        // Without a team the send records no recipient team pivot, which hides it from every log and
        // stat, and leaves the database channel with no team to write a notification against.
        $communicationTemplateGroup->team_id = $teamId ?: currentTeamId();
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

            $attributes = [
                'subject' => collect(array_keys(config('kompo.locales')))->mapWithKeys(fn($locale) => [$locale => $communicationTemplate->title]),
                'content' => $content->toArray(),
            ];

            // A database notification's CTA lives on its button, not the body. When the trigger
            // declares a default button handler, seed it on the DATABASE template.
            if ($type === CommunicationType::DATABASE
                && method_exists($trigger, 'defaultNotificationButtonHandler')
                && ($handler = $trigger::defaultNotificationButtonHandler())) {
                $attributes['custom_button_handler'] = $handler;
            }

            $type->handler(null)->save($communicationTemplate->id, $attributes);
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
        // A partial clone (group saved, templates half-written) would leave a broken override that
        // the resolver would still pick up. Wrap the whole deep clone so it is all-or-nothing.
        return \DB::transaction(function () use ($teamId) {
            // One group per team per trigger — app-enforced (the DB unique was relaxed so a team can
            // own several manual communications). The guard runs inside the transaction with a row
            // lock so two concurrent copies (e.g. a double-submit) can't both pass exists() and then
            // each insert a duplicate override. Fail fast with a clear message on a duplicate copy.
            $existing = static::query()->where('team_id', $teamId)
                ->where('trigger', $this->trigger)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new \RuntimeException(
                    "Team {$teamId} already owns a communication template for trigger [{$this->trigger}]."
                );
            }

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