<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface EmailCommunicable extends Communicable 
{
    public function getEmail();
}