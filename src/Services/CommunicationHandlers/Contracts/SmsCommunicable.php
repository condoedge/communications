<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface SmsCommunicable extends Communicable 
{
    /**
     * Get the phone number of the communicable where the communication will be sent
     * 
     * @return string
     */
    public function getPhone();
}