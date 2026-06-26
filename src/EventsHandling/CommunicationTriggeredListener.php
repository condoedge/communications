<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\Dispatch\CommunicationDispatchServiceContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        $groups->each->notify($event->getCommunicables(), null, $params);
    }

    /**
     * Derive the recipient's team for resolution:
     *   1. explicit hook getCommunicationTeamId()  (preferred — RecipientOverride / DTO carriers)
     *   2. a plain team_id attribute on the communicable
     *   3. $params['team_id'] (event-level fallback)
     *   4. null  -> the global system-baseline bucket
     */
    protected function teamIdFor($communicable, array $params): ?int
    {
        if (is_object($communicable) && method_exists($communicable, 'getCommunicationTeamId')) {
            $teamId = $communicable->getCommunicationTeamId();

            if ($teamId !== null) {
                return (int) $teamId;
            }
        }

        if ($communicable instanceof EloquentModel && $communicable->getAttribute('team_id') !== null) {
            return (int) $communicable->getAttribute('team_id');
        }

        if (is_object($communicable) && isset($communicable->team_id)) {
            return (int) $communicable->team_id;
        }

        if (isset($params['team_id'])) {
            return (int) $params['team_id'];
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
