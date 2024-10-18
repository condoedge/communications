<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface DatabaseCommunicable extends Communicable 
{
    public function getId();
    public function hasTeam($teamId);
}