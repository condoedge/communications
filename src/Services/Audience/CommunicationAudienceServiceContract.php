<?php

namespace Condoedge\Communications\Services\Audience;

use Illuminate\Support\Collection;

/**
 * Resolves the set of communicable recipients for a manual broadcast or a team-scoped trigger.
 *
 * Mirrors EventAudienceService: it owns team expansion (own ∪ descendants), member-type
 * composition, optional staff (permission holders) and guardian unions, empty-email filtering,
 * and final deduplication by lowercase email — preferring the Person instance over a User when
 * the same address is reachable as both.
 */
interface CommunicationAudienceServiceContract
{
    /**
     * Resolve the deduped recipient collection for the given anchor teams.
     *
     * @param int[] $teamIds anchor teams; when $spec is provided its `teams` take precedence
     * @return Collection<int, \Illuminate\Database\Eloquent\Model> deduped by lowercase email
     */
    public function resolveCommunicables(array $teamIds, ?AudienceSpec $spec = null): Collection;
}
