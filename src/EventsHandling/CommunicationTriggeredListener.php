<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\EventsHandling\Contracts\TeamScopedCommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Recipients\RecipientOverride;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\Dispatch\CommunicationDispatchServiceContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kompo\Auth\Models\Plugins\HasSecurity;

class CommunicationTriggeredListener implements ShouldQueue
{
    use InteractsWithQueue;

    /** Window a fired event stays deduplicated for (covers queue retries / accidental re-dispatch). */
    protected const IDEMPOTENCY_TTL_MINUTES = 10;

    /**
     * Route a fired trigger to its team(s) and send through the EffectiveTemplateResolver.
     *
     * Real triggers declare their teams via TeamScopedCommunicableEvent::getCommunicationTeams():
     * exactly one team resolves that team's effective template (honoring its override / disable);
     * several (a broadcast) or none fall back to the system baseline. The send is recorded against
     * every targeted team — per recipient, see CommunicationSending::writeRecipientRows — counted
     * once. Manual sends (ManualTrigger::getSpecificCommunicationsIds) keep their "notify these
     * specific groups" path.
     */
    public function handle(CommunicableEvent $event)
    {
        // The communications are a safe, controlled place — admins set which info to show in them —
        // so the listener runs in a bypass context to read all the data it needs to build the comms.
        // finally{} guarantees the context is released on every exit (early return or thrown error),
        // otherwise the bypass would leak into later jobs on a long-lived queue worker.
        HasSecurity::enterBypassContext();

        try {
            Log::info('Handling communication triggered event', ['event' => get_class($event)]);

            // Manual / direct-usage sends pick explicit group ids and bypass team resolution entirely.
            if (method_exists($event, 'getSpecificCommunicationsIds')) {
                $this->handleManual($event);

                return;
            }

            $communicables = collect($event->getCommunicables())->values();

            if ($communicables->isEmpty()) {
                return;
            }

            // Idempotency: skip a replay of the same (trigger + identity + recipient set). Cache::add is
            // atomic on the shared store (Redis in prod), so concurrent queue retries collapse to one.
            if (!$this->claimIdempotencyKey($event, $communicables)) {
                Log::info('Skipping duplicate communication dispatch', ['event' => get_class($event)]);

                return;
            }

            $teams = $event instanceof TeamScopedCommunicableEvent
                ? collect($event->getCommunicationTeams())->map(fn ($id) => (int) $id)->filter()->unique()->values()->all()
                : [];

            // Exactly one team uses that team's effective template; several or none fall back to the
            // baseline. Per-team appearance comes from the recipient team pivot, not from the template.
            $templateTeamId = count($teams) === 1 ? $teams[0] : null;

            app(CommunicationDispatchServiceContract::class)->dispatchForTrigger(
                $event::class,
                $event,
                $communicables,
                $templateTeamId,
                $teams,
            );
        } finally {
            HasSecurity::exitBypassContext();
        }
    }

    /**
     * Original manual-send path: notify the explicitly chosen (valid) groups with all communicables.
     */
    protected function handleManual(CommunicableEvent $event): void
    {
        $params = ContextEnhancer::setContext(array_merge($event->getParams(), [
            'trigger' => $event::class,
            'trigger_instance' => $event,
        ]))->getEnhancedContext();

        $groups = CommunicationTemplateGroup::forTrigger($event::class)->hasValid()
            ->whereIn('id', $event->getSpecificCommunicationsIds())
            ->get();

        // The manual path has no per-recipient team resolution, so stamp each send with its group's
        // own team — that is the sending team — so it shows in that team's send log / overview.
        $groups->each(function ($group) use ($event, $params) {
            $group->notify(
                $event->getCommunicables(),
                null,
                array_merge($params, [
                    'team_id' => $group->team_id,
                    'communication_teams' => array_filter([$group->team_id]),
                ]),
            );
        });
    }

    /**
     * Reserve the dedup key for this fire. Returns false when the same event was already handled
     * within the TTL. Identity prefers an explicit getIdempotencyKey(); otherwise it is the event's
     * params plus the sorted recipient signatures, so two genuinely different sends never collide.
     */
    protected function claimIdempotencyKey(CommunicableEvent $event, Collection $communicables): bool
    {
        if (is_object($event) && method_exists($event, 'getIdempotencyKey')) {
            $identity = (string) $event->getIdempotencyKey();
        } else {
            $signature = $communicables
                ->map(fn ($communicable) => $this->recipientSignature($communicable))
                ->sort()
                ->values()
                ->implode(',');

            $identity = md5(json_encode($event->getParams()) . '|' . $signature);
        }

        $key = 'comm-trigger:' . md5($event::class . '|' . $identity);

        return Cache::add($key, true, now()->addMinutes(self::IDEMPOTENCY_TTL_MINUTES));
    }

    /**
     * A stable per-recipient identity for the idempotency signature — it MUST survive the queue's
     * unserialize on retry, otherwise the dedup key changes and the "duplicate" sends again.
     */
    protected function recipientSignature($communicable): string
    {
        // Unwrap the send-time decorator so the same underlying recipient always hashes the same.
        if ($communicable instanceof RecipientOverride) {
            $communicable = $communicable->getInner();
        }

        if ($communicable instanceof EloquentModel) {
            return get_class($communicable) . ':' . $communicable->getKey();
        }

        if ($communicable instanceof EmailCommunicable) {
            $email = secureCallCb(fn () => $communicable->getEmail());

            if ($email) {
                return 'email:' . mb_strtolower((string) $email);
            }
        }

        // No stable key/email: hash the serialized state. Unlike spl_object_hash (a fresh per-instance
        // pointer that differs after the queue re-unserializes the event on retry), serialize()
        // reproduces the same bytes for the same object state, keeping dedup stable across retries.
        return 'ser:' . (secureCallCb(fn () => md5(serialize($communicable))) ?? spl_object_hash($communicable));
    }
}
