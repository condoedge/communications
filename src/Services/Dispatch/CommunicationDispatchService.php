<?php

namespace Condoedge\Communications\Services\Dispatch;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Recipients\RecipientKey;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\HasCommunicationTeam;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CommunicationDispatchService implements CommunicationDispatchServiceContract
{
    public function __construct(
        protected EffectiveTemplateResolverContract $resolver,
    ) {
    }

    public function dispatchForTrigger(
        string $trigger,
        CommunicableEvent $event,
        array|Collection $communicables,
        array $communicationTeams = [],
    ): bool {
        $communicables = collect($communicables)->values();
        $teams = $this->normalizeTeams($communicationTeams);

        if (count($teams) <= 1) {
            return $this->dispatchForTeam($trigger, $event, $communicables, $teams[0] ?? null, $teams);
        }

        // A broadcast resolves per team, so a team that disabled the trigger is not served by the
        // baseline behind its back. Recipients are partitioned first: resolving per team without
        // partitioning would send one message per team to anyone belonging to several of them.
        $dispatched = false;
        $lastError = null;

        foreach ($this->partitionByTeam($communicables, $teams) as $teamId => $bucket) {
            try {
                $dispatched = $this->dispatchForTeam(
                    $trigger,
                    $event,
                    collect($bucket),
                    $teamId ?: null,
                    $teamId ? [$teamId] : $teams,
                ) || $dispatched;
            } catch (\Throwable $e) {
                $lastError = $e;

                Log::error('Team dispatch failed', ['trigger' => $trigger, 'team_id' => $teamId, 'exception' => $e]);
            }
        }

        // Only propagate when no team got through. The listener releases its idempotency claim on a
        // throw, which is sound only while nothing has been delivered — letting a late team's failure
        // escape after an earlier team already sent would make the retry duplicate that team.
        if ($lastError && !$dispatched) {
            throw $lastError;
        }

        return $dispatched;
    }

    /**
     * @param int[] $communicationTeams the teams these recipients are recorded against
     */
    protected function dispatchForTeam(
        string $trigger,
        CommunicableEvent $event,
        Collection $communicables,
        ?int $teamId,
        array $communicationTeams,
    ): bool {
        if ($communicables->isEmpty()) {
            return false;
        }

        // resolve() with a non-existent team id (0) walks an empty hierarchy and falls straight
        // through to the team_id IS NULL baseline.
        $resolution = $this->resolver->resolve($trigger, $teamId ?? 0);

        if (!$resolution->isSendable() || !$resolution->group) {
            // Logged rather than silent: a suppressed send is otherwise indistinguishable from a
            // lost one, which is the hardest thing to diagnose in this pipeline.
            Log::info('Communication not dispatched: no sendable template', [
                'trigger' => $trigger,
                'team_id' => $teamId,
                'source' => $resolution->source->value,
                'recipients' => $communicables->count(),
            ]);

            return false;
        }

        $eventParams = $event->getParams();

        // team_id is the sending's template/header team; communication_teams is the full set the send
        // is recorded against (the recipient team pivot) so it appears in every team, counted once.
        // teams_ids is the database channel's audience, which is NOT always the same set — see
        // notificationAudienceFor().
        $params = ContextEnhancer::setContext(array_merge($eventParams, [
            'trigger' => $trigger,
            'trigger_instance' => $event,
            'team_id' => $teamId,
            'communication_teams' => $communicationTeams,
            'teams_ids' => $this->notificationAudienceFor($eventParams, $communicationTeams, $teamId),
        ]))->getEnhancedContext();

        $resolution->group->notify($communicables, null, $params);

        return true;
    }

    /**
     * The teams the database channel writes notification rows under.
     *
     * Deliberately not the same concept as the communication's team scope: communication_teams
     * decides which team's template resolves and which teams the send is counted under, while this
     * decides which teams a recipient can actually receive the notice in. The writer keeps a row only
     * where $communicable->hasTeam($teamId), and team access flows downward (own team, descendants,
     * siblings) — never up to an ancestor.
     *
     * So an event whose audience sits BELOW its own team has to declare the two differently: a
     * yearly-registration notice is scoped to the event's team but notifies permission holders in
     * that team's children, who never "have" the parent. Substituting the scope for the audience
     * made the two sets disjoint and dropped every row silently, so an event that declares an
     * audience of its own keeps it.
     *
     * Emptiness decides, not null: an event that declares the key but resolves it to [] or [null]
     * has no usable audience and must still fall back rather than mute the send.
     *
     * @param array $eventParams the event's own params, before this service merges its values over them
     * @param int[] $communicationTeams
     * @return int[]
     */
    protected function notificationAudienceFor(array $eventParams, array $communicationTeams, ?int $teamId): array
    {
        // collect() rather than a cast: an event may hand back a Collection here, and (array) on one
        // yields its internal shape instead of its items.
        $declared = $this->normalizeTeams(collect($eventParams['teams_ids'] ?? [])->all());

        return $declared ?: ($communicationTeams ?: array_filter([$teamId]));
    }

    /**
     * Bucket each recipient under the first targeted team it belongs to; recipients that declare no
     * team fall in bucket 0 and are served once by the baseline.
     *
     * @param int[] $teams
     * @return array<int, array>
     */
    protected function partitionByTeam(Collection $communicables, array $teams): array
    {
        $buckets = [];

        foreach ($communicables as $communicable) {
            $buckets[$this->teamForRecipient($communicable, $teams)][] = $communicable;
        }

        return $buckets;
    }

    /**
     * @param int[] $teams
     */
    protected function teamForRecipient($communicable, array $teams): int
    {
        $identity = RecipientKey::unwrap($communicable);

        if (!$identity instanceof HasCommunicationTeam) {
            return 0;
        }

        $own = $this->normalizeTeams($identity->getCommunicationTeamIds());

        // First match wins: a recipient in several targeted teams still receives exactly one message.
        foreach ($teams as $teamId) {
            if (in_array($teamId, $own, true)) {
                return $teamId;
            }
        }

        return 0;
    }

    /**
     * @return int[]
     */
    protected function normalizeTeams(array $teamIds): array
    {
        return collect($teamIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }
}
