<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

/**
 * Optional capability: a communicable that authoritatively declares which team a communication to
 * it should be attributed to (template resolution + send-log / stats scoping).
 *
 * This is the top-priority source in CommunicationTriggeredListener::teamIdFor(). A recipient
 * (Person / User) belongs to many teams, so their incidental `team_id` is the wrong axis — implement
 * this to return the real owning team, e.g. the common PARENT of several unit teams the event is
 * about, so the stat rolls up to that parent rather than scattering across units.
 *
 * Return null to defer to the event's team and the remaining fallbacks.
 */
interface HasCommunicationTeam
{
    public function getCommunicationTeamId(): ?int;
}
