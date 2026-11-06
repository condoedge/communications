<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

/**
 * One address, one message. An implementation that also declares getEmailGroup(): string[]
 * receives a single message addressed to all of those addresses instead.
 */
interface EmailCommunicable extends Communicable
{
    /**
     * Get the email of the communicable where the communication will be sent
     * 
     * @return string
     */
    public function getEmail();
}