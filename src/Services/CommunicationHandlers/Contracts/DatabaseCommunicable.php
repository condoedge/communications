<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface DatabaseCommunicable extends Communicable 
{
    /**
     * Get the id of the communicable where the notification will be associated. for now just for users. but we can change it later
     * 
     * @return number|string
     */
    public function getUserId();

    /**
     * Validate if the communicable has the team to send the notification
     * @param number $teamId
     * @return bool
     */
    public function hasTeam($teamId);
}