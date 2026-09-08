<?php

namespace Condoedge\Communications\EventsHandling\Contracts;

/**
 * A communicable event that targets one or more teams. The teams it declares drive:
 *  - template resolution: exactly one team uses that team's effective template / override; several
 *    (a broadcast) or none fall back to the system baseline; and
 *  - per-recipient stats scoping: each recipient is recorded against these teams (so the send appears
 *    in every one of them, counted once) — unless the recipient declares its own via
 *    {@see \Condoedge\Communications\Services\CommunicationHandlers\Contracts\HasCommunicationTeam}.
 *
 * This is the single declared source of a communication's team scope — there is no implicit fallback
 * to a recipient's incidental team_id. Return [] for an unscoped (system-baseline) communication.
 */
interface TeamScopedCommunicableEvent
{
    /** @return int[] the team ids this communication targets */
    public function getCommunicationTeams(): array;
}
