<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

/**
 * ONE ADDRESS, ONE MESSAGE — what every implementation of this interface means unless it says
 * otherwise.
 *
 * AN IMPLEMENTATION MAY OPTIONALLY DECLARE `getEmailGroup(): string[]` to say that it stands for a
 * set of addresses that must share ONE message: the handler then addresses a single mail to the
 * whole list rather than one mail per address. It is deliberately NOT a method on this interface —
 * see Condoedge\Communications\Recipients\EmailGroup for why absence has to be the permissive
 * default, and for what a group costs in per-address delivery reporting.
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