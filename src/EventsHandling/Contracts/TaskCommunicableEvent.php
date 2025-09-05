<?php

namespace Condoedge\Communications\EventsHandling\Contracts;

interface TaskCommunicableEvent extends CommunicableEvent
{
    /**
     * @return array<Kompo\Tasks\Models\Contracts\TaskAssignable>
     */
    public function sendToAnotherAssignables(): ?array;
}