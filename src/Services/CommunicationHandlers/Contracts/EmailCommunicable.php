<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface EmailCommunicable extends Communicable 
{
    /**
     * Get the email of the communicable where the communication will be sent
     * 
     * @return string
     */
    public function getEmail();
}