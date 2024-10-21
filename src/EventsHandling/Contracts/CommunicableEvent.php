<?php

namespace Condoedge\Communications\EventsHandling\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface CommunicableEvent
{
    /**
     * Give the context of the event to replace the content of the communication or inject context into the records
     * @return array<string, mixed>
     */
    function getParams(): array;

    /**
     * Get the communicables to notify. Ex: Users, Persons, Customers
     * @return array
     */
    function getCommunicables(): Collection|array;

    /**
     * The name of the event to show it.
     * Case of use: CommunicationTemplateForm::body
     * @see \Condoedge\Communications\Components\CommunicationTemplateForm::body
     * 
     * @return string
     */
    static function getName(): string;

    /**
     * The variables that the communication template should have, used to filter the enhanced editor variables.
     * Return null to allow everything.
     * 
     * @return array|null
     */
    static function validVariablesIds(): ?array;
}