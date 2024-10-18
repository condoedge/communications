<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

interface SmsCommunicable extends Communicable 
{
    public function getPhone();
}