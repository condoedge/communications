<?php

namespace Condoedge\Communications\EventsHandling;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\EventsHandling\Contracts\TeamScopedCommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Recipients\RecipientKey;
use Condoedge\Communications\Services\Dispatch\CommunicationDispatchServiceContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kompo\Auth\Models\Plugins\HasSecurity;

class CommunicationTriggeredListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Window an event with an explicit getIdempotencyKey() stays deduplicated for. Only events that
     * opt in get a long window: they alone know that two fires mean the same business fact.
     */
    protected const IDEMPOTENCY_TTL_MINUTES = 10;

    /**
     * Window the implicit content-based guard collapses fires into. It exists to absorb an
     * accidental double dispatch (a model hook firing on both `created` and `saved`), not to decide
     * that two business events minutes apart are the same — identical params and recipients are
     * common and legitimate, so a long window here silently destroys real communications.
     */
    protected const REPLAY_WINDOW_SECONDS = 30;

    protected ?string $idempotencyKey = null;

    protected ?string $claimOwner = null;

    /**
     * Route a fired trigger to its team(s) and send through the EffectiveTemplateResolver.
     *
     * Real triggers declare their teams via TeamScopedCommunicableEvent::getCommunicationTeams();
     * the dispatch service owns what those teams mean (baseline, single team, or per-team
     * broadcast). Manual sends (ManualTrigger::getSpecificCommunicationsIds) keep their "notify
     * these specific groups" path.
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
                Log::info('No communicables for trigger; nothing to dispatch', ['event' => get_class($event)]);

                return;
            }

            $this->idempotencyKey = $this->idempotencyKeyFor($event, $communicables);

            if (!$this->claimIdempotencyKey($this->idempotencyKey, $event)) {
                Log::info('Skipping duplicate communication dispatch', [
                    'event' => get_class($event),
                    'key' => $this->idempotencyKey,
                    'recipients' => $communicables->count(),
                ]);

                return;
            }

            $teams = $event instanceof TeamScopedCommunicableEvent
                ? collect($event->getCommunicationTeams())->map(fn ($id) => (int) $id)->filter()->unique()->values()->all()
                : [];

            try {
                app(CommunicationDispatchServiceContract::class)->dispatchForTrigger(
                    $event::class,
                    $event,
                    $communicables,
                    $teams,
                );
            } catch (\Throwable $e) {
                // Nothing is known to have been sent, so the claim must not outlive the attempt:
                // holding it would make the queue retry look like a duplicate and exit green.
                $this->releaseIdempotencyKey();

                throw $e;
            }
        } finally {
            HasSecurity::exitBypassContext();
        }
    }

    /**
     * Last-chance release once the queue gives up, so an operator's `queue:retry` is not swallowed
     * as a duplicate.
     *
     * The queue builds a fresh listener per attempt, so $idempotencyKey is not carried over here.
     * Only an explicit getIdempotencyKey() is deterministic enough to recompute — the implicit
     * content key is bucketed on time and would resolve to a different key. That is acceptable:
     * implicit claims expire within seconds, explicit ones hold for the full window.
     */
    public function failed(CommunicableEvent $event, \Throwable $e): void
    {
        if (!$this->idempotencyKey && method_exists($event, 'getIdempotencyKey')) {
            $this->idempotencyKey = $this->idempotencyKeyFor($event, collect());
        }

        $this->releaseIdempotencyKey();
    }

    /**
     * Original manual-send path: notify the explicitly chosen (valid) groups with all communicables.
     */
    protected function handleManual(CommunicableEvent $event): void
    {
        $groups = CommunicationTemplateGroup::forTrigger($event::class)->hasValid()
            ->whereIn('id', $event->getSpecificCommunicationsIds())
            ->get();

        if ($groups->isEmpty()) {
            Log::warning('Manual communication has no valid group to send', [
                'event' => get_class($event),
                'requested' => $event->getSpecificCommunicationsIds(),
            ]);

            return;
        }

        // The manual path has no per-recipient team resolution, so stamp each send with its group's
        // own team — that is the sending team — so it shows in that team's send log / overview.
        $groups->each(function ($group) use ($event) {
            $teams = array_filter([$group->team_id]);

            $params = ContextEnhancer::setContext(array_merge($event->getParams(), [
                'trigger' => $event::class,
                'trigger_instance' => $event,
                'team_id' => $group->team_id,
                'communication_teams' => $teams,
                'teams_ids' => $teams,
            ]))->getEnhancedContext();

            $group->notify($event->getCommunicables(), null, $params);
        });
    }

    /**
     * Reserve the key for this fire.
     *
     * Returns false ONLY when a different fire is demonstrably holding it. A cache store that
     * cannot hold a value fails OPEN: a duplicate communication is recoverable, one that is never
     * sent is not, and a store returning false for every add would otherwise mute the whole system.
     */
    protected function claimIdempotencyKey(string $key, CommunicableEvent $event): bool
    {
        $ttl = method_exists($event, 'getIdempotencyKey')
            ? now()->addMinutes(self::IDEMPOTENCY_TTL_MINUTES)
            : now()->addSeconds(self::REPLAY_WINDOW_SECONDS * 2);

        if (Cache::add($key, $this->claimOwner(), $ttl)) {
            return true;
        }

        $held = Cache::get($key);

        // Our own unfinished attempt (the worker died mid-send and never released). Re-enter.
        if ($held === $this->claimOwner()) {
            return true;
        }

        // add() failed but nothing is stored: a null/array store, an unwritable file cache, or a
        // lock timeout. That is "cannot deduplicate", not "already sent".
        if ($held === null) {
            Log::warning('Idempotency store unusable; dispatching without deduplication', [
                'key' => $key,
                'store' => config('cache.default'),
            ]);

            return true;
        }

        return false;
    }

    protected function releaseIdempotencyKey(): void
    {
        if ($this->idempotencyKey) {
            Cache::forget($this->idempotencyKey);
        }
    }

    /**
     * Identity for this fire.
     *
     * An event that declares getIdempotencyKey() controls its own deduplication and gets the full
     * window. Everything else is identified by its params plus recipient set, bucketed into a short
     * replay window: that collapses an accidental double dispatch without ever merging two distinct
     * business events that happen to carry the same payload.
     */
    protected function idempotencyKeyFor(CommunicableEvent $event, Collection $communicables): string
    {
        if (method_exists($event, 'getIdempotencyKey')) {
            $raw = $event->getIdempotencyKey();

            if (is_scalar($raw) && (string) $raw !== '') {
                return 'comm-trigger:' . md5($event::class . '|explicit|' . $raw);
            }

            Log::warning('getIdempotencyKey() returned an unusable value; falling back to content identity', [
                'event' => $event::class,
            ]);
        }

        $signature = $communicables
            ->map(fn ($communicable) => RecipientKey::for($communicable))
            ->sort()
            ->values()
            ->implode(',');

        $params = json_encode($event->getParams(), JSON_PARTIAL_OUTPUT_ON_ERROR);

        // json_encode still returns false on failures partial output cannot rescue; never let the
        // params component silently collapse to an empty string shared by every such event.
        if ($params === false) {
            $params = 'unencodable:' . md5(serialize(array_keys($event->getParams())));
        }

        $bucket = intdiv(now()->getTimestamp(), self::REPLAY_WINDOW_SECONDS);

        return 'comm-trigger:' . md5($event::class . '|' . md5($params . '|' . $signature) . '|' . $bucket);
    }

    /**
     * Stable across retries of this job, unique per genuine dispatch. Memoized: a fresh value per
     * call would never match the stored claim, defeating the re-entry check above.
     */
    protected function claimOwner(): string
    {
        return $this->claimOwner ??= $this->job?->uuid() ?? 'sync:' . Str::uuid()->toString();
    }
}
