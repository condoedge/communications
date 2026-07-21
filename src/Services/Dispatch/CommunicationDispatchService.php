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

        // team_id is the sending's template/header team; communication_teams is the full set the send
        // is recorded against (the recipient team pivot) so it appears in every team, counted once.
        // teams_ids carries the same set to the database channel, which writes one notification row
        // per (recipient, team).
        $params = ContextEnhancer::setContext(array_merge($event->getParams(), [
            'trigger' => $trigger,
            'trigger_instance' => $event,
            'team_id' => $teamId,
            'communication_teams' => $communicationTeams,
            'teams_ids' => $communicationTeams ?: array_filter([$teamId]),
        ]))->getEnhancedContext();

        $resolution->group->notify($communicables, null, $params);

        return true;
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
