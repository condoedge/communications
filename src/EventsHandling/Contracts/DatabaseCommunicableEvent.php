<?php

namespace Condoedge\Communications\EventsHandling\Contracts;

interface DatabaseCommunicableEvent extends CommunicableEvent
{
    public static function getValidRoutes(): array;
}