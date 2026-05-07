<?php

namespace Condoedge\Communications\EventsHandling\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TaskCommunicableEvent extends CommunicableEvent
{
    /**
     * @return array<Kompo\Tasks\Models\Contracts\TaskAssignable>
     */
    public function sendToAnotherAssignables(): ?array;

    public function getRelatedTaskable(): ?Model;
}