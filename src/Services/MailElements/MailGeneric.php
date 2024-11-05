<?php

namespace Condoedge\Communications\Services\MailElements;

class MailGeneric extends MailElement
{
    protected $callback;

    public function __construct($callback)
    {
        $this->callback = $callback;
    }

    public function htmlStructure()
    {
        $callback = $this->callback;

        return $callback();
    }
}