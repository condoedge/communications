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
            ->whereIn('type', $this->getValidCommTypesForTrigger($this->trigger))
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

    protected function getValidCommTypesForTrigger(string $trigger): array
    {
        return collect(CommunicationType::cases())
            ->reject(fn($t) => !$t->enabled())
            ->filter(fn($t) => !method_exists($trigger, 'acceptsChannel') || $trigger::acceptsChannel($t))
            ->map(fn($t) => $t->value)
            ->all();
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

            // Rendered INSIDE executeCallbackInLocale, and picking the per-locale stub file is not
            // enough on its own. The file supplies the prose, but every __() evaluated DURING the
            // render resolves against the ACTIVE locale — and mention chips are built that way,
            // HtmlMentionParser::buildVar() emitting '<span data-mention="ID">' . __($var[1]) . '</span>'.
            // Without the switch, seeding under a French default stamps the English template with
            // English prose and FRENCH chip labels. It hides well: the row looks like a complete
            // translation map, the sentences are right, and the only reader who sees it is the admin
            // who later opens that template in the editor. It is also permanent — baselineExists()
            // asks whether the GROUP exists, so re-seeding reports "Nothing to do." and never
            // re-derives a body.
            //
            // Hoisting one switch around the whole mapWithKeys was the rejected alternative: it
            // renders every locale under whichever one happened to be first, which is the same bug
            // with a tidier shape. The sibling 'subject' mapping below resolves per locale for this
            // reason; content had simply never been given the same treatment.
            $content = collect(array_keys(config('kompo.locales')))->mapWithKeys(function($locale) use ($viewName) {
                if (!file_exists(resource_path('views/' . $viewName . '-' . $locale . ".blade.php"))) return [$locale => ''];

                return [$locale => executeCallbackInLocale(
                    $locale,
                    fn() => view($viewName .'-'. $locale)->render(),
                )];
            })->filter();

            if (!$content->count()) {
                return null;
            }

            // getName() is the trigger's ADMIN-FACING title; this is the SUBJECT LINE a recipient
            // reads. They are usually the same sentence, so a trigger that says nothing keeps
            // getting its name — but where the two genuinely differ, collapsing them back means
            // the next seed publishes the admin title as the recipient subject, turning a sentence
            // about an order into one about a delivery, with a green suite and no error. Such a
            // trigger declares `public static function getSubject(): string` and that answers here,
            // and only when a group is CREATED — TemplateSeedingService skips a trigger whose
            // baseline row already exists (TemplateSeedingService:33), so adding getSubject() later
            // never rewrites the baseline. It is not inert, though: seedOwnedGroup() in
            // CommunicationTemplatesList (:343) also builds through here, so a team that configures
            // its own override after the change gets the new subject while the baseline keeps the
            // old one. Reconcile in the admin rather than re-deriving on read — a seeded row is the
            // source of truth and an admin edit must not be silently reverted by a later deploy.
            //
            // An empty return falls back to getName() rather than seeding it, because an empty
            // subject is indistinguishable from a correctly-seeded row in the database,
            // TemplateSeedingService never re-derives it, and it surfaces only as a subject-less
            // email in a recipient's inbox.
            //
            // Duck-typed rather than a fifth method on CommunicableEvent: every registered trigger
            // in every host already implements that interface, so adding a method to it makes all
            // of them fatal on the deploy that ships this line. A trait carrying a default fails
            // the same way, one deploy later: the seam would have to call getSubject() unguarded,
            // so a trigger that forgets to `use` the trait is a fatal undefined-method at seed
            // time — and the forgetting is invisible at review, because a trigger looks complete
            // without it. method_exists has no such precondition.
            //
            // Not a second opt-in interface either. The package ships three
            // (TeamScopedCommunicableEvent, DatabaseCommunicableEvent, TaskCommunicableEvent) and
            // can class-string-test them — AbstractCommunicationHandler::typeIsValidToTrigger()
            // does exactly that with class_implements(), as do ServiceProvider:193 and
            // ReminderRegistry:68 — so the mechanism is available. It loses on a different ground:
            // an interface is permissive only for hosts that hear it exists, and a trigger whose
            // author never imported it seeds the admin title to recipients with no signal, the
            // exact failure this seam prevents. A duck-typed static is permissive by ABSENCE, which
            // is what makes the 34 already-registered triggers correct without an edit, and it is
            // the shape four other trigger hooks already use (acceptsChannel :131,
            // defaultNotificationButtonHandler :244, validNotificationButtonHandlers
            // TemplateSeedingService:70, defaultNotificationButtonLabelAndAction
            // TemplateSeedingService:130).
            //
            // Resolved INSIDE executeCallbackInLocale. Hoisting the call out of the closure would
            // stamp every locale with the wording of whichever locale happened to be active when
            // the seeder ran: the row still looks like a complete translation map, and the mistake
            // surfaces only to the recipient who receives the wrong language.
            $attributes = [
                'subject' => collect(array_keys(config('kompo.locales')))
                    ->mapWithKeys(fn($locale) => [$locale => executeCallbackInLocale(
                        $locale,
                        fn() => (method_exists($trigger, 'getSubject') ? $trigger::getSubject() : null) ?: $trigger::getName(),
                    )]),
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