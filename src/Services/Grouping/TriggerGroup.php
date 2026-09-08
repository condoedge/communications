<?php

namespace Condoedge\Communications\Services\Grouping;

/**
 * Value object describing a logical group a trigger belongs to (used to organise + filter the
 * admin Templates tab). The host app maps its own domain grouping onto these.
 */
class TriggerGroup
{
    public function __construct(
        protected string $value,
        protected string $label,
        protected string $color = '',
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function color(): string
    {
        return $this->color;
    }
}
