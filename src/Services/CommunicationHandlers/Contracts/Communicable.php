<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Contracts;

/**
 * Interface Communicable
 * 
 * This interface is used to define the methods that a communicable object should have
 */
interface Communicable 
{
    /**
     * The name of the communicable on context to be used in the communication
     * @return string
     */
    public function getContextKey();
}