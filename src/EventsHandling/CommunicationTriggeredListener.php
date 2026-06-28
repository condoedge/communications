<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\HasCommunicationTeam;
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
     * Route a fired trigger to the right template per recipient team.
     *
     * Real triggers: group the event's communicables by their recipient team and dispatch once per
     * team through the EffectiveTemplateResolver, so per-team override/disable is honored at send
     * time. Manual sends (ManualTrigger::getSpecificCommunicationsIds + direct_usage groups) keep
     * their original "notify these specific groups" behavior untouched.
     */
    public function handle(CommunicableEvent $event)
    {
        // The communications are a safe controlated place, since admins set which info to show in them.
        // So we ensure that the comms listener runs in a bypass context, so it can read all the data it needs to build the comms.
        HasSecurity::enterBypassContext();

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

        $dispatcher = app(CommunicationDispatchServiceContract::class);
        $baseParams = $event->getParams();

        $communicables
            ->groupBy(fn ($communicable) => $this->teamIdFor($communicable, $baseParams) ?? '__none__')
            ->each(function (Collection $people, $teamKey) use ($event, $dispatcher) {
                $teamId = $teamKey === '__none__' ? null : (int) $teamKey;

                $dispatcher->dispatchForTrigger(
                    $event::class,
                    new ScopedCommunicableEvent($event, $people->values()),
                    $teamId
                );
            });

        HasSecurity::exitBypassContext();
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
                array_merge($params, ['team_id' => $group->team_id]),
            );
        });
    }

    /**
     * Derive the team this send routes to (template resolution + send-log / stats scoping):
     *   1. explicit hook getCommunicationTeamId() — the authoritative source (e.g. rolls a set of
     *      unit teams up to their owning parent).
     *   2. the EVENT's subject team ($params['team_id'], then the $params['team'] object) — a comm
     *      is usually about an event in a team, not about the recipient.
     *   3. the recipient's own team_id — last resort only, since a Person/User belongs to many teams.
     *   4. null -> the system-baseline bucket.
     *
     * There is no teams_ids fallback: picking the first of several teams silently misattributes the
     * send. When an event spans many teams, the owning team (their common parent) must come from the
     * explicit hook in (1).
     */
    protected function teamIdFor($communicable, array $params): ?int
    {
        if ($communicable instanceof HasCommunicationTeam
            || (is_object($communicable) && method_exists($communicable, 'getCommunicationTeamId'))) {
            $teamId = $communicable->getCommunicationTeamId();

            if ($teamId !== null) {
                return (int) $teamId;
            }
        }

        if (isset($params['team_id'])) {
            return (int) $params['team_id'];
        }

        $team = $params['team'] ?? null;
        if (is_object($team) && isset($team->id)) {
            return (int) $team->id;
        }

        if ($communicable instanceof EloquentModel && $communicable->getAttribute('team_id') !== null) {
            return (int) $communicable->getAttribute('team_id');
        }

        if (is_object($communicable) && isset($communicable->team_id)) {
            return (int) $communicable->team_id;
        }

        return null;
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

    protected function recipientSignature($communicable): string
    {
        if ($communicable instanceof EloquentModel) {
            return get_class($communicable) . ':' . $communicable->getKey();
        }

        if (is_object($communicable) && method_exists($communicable, 'getEmail')) {
            try {
                return 'email:' . $communicable->getEmail();
            } catch (\Throwable $e) {
                // fall through to object hash below
            }
        }

        return 'obj:' . spl_object_hash($communicable);
    }
}
