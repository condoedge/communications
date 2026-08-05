<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

/**
 * Optional capability on a communicable (recipient): declare exactly which teams a communication to
 * it should be recorded against, overriding the event's {@see TeamScopedCommunicableEvent} teams.
 *
 * Use it for the case where one send has recipients that each belong to different teams — e.g. a
 * roster notice where every recipient is recorded only against their own team. Return [] to defer to
 * the event's teams.
 */
interface HasCommunicationTeam
{
    /** @return int[] the team ids this recipient should be recorded against */
    public function getCommunicationTeamIds(): array;
}
