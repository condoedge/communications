<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface TaskCommunicable extends Communicable 
{
    /**
     * Get the id of the communicable where the notification will be associated
     * 
     * @return number|string
     */
    public function getId();
}