<?php

namespace Condoedge\Communications\Models;

enum CommunicationSendingRecipientStatus: int
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case PENDING = 1;
    case SENT = 2;
    case FAILED = 3;
    case DELIVERED = 4;
    case OPENED = 5;
    case CLICKED = 6;
    case BOUNCED = 7;

    public function label()
    {
        return match ($this) {
            self::PENDING => __('communications.pending'),
            self::SENT => __('communications.sent'),
            self::FAILED => __('communications.failed'),
            self::DELIVERED => __('communications.delivered'),
            self::OPENED => __('communications.opened'),
            self::CLICKED => __('communications.clicked'),
            self::BOUNCED => __('communications.bounced'),
        };
    }

    public function classes()
    {
        return match ($this) {
            self::SENT, self::DELIVERED => 'bg-info',
            self::OPENED, self::CLICKED => 'bg-positive',
            self::FAILED, self::BOUNCED => 'bg-danger',
            self::PENDING => 'bg-warning',
        };
    }

    public function statusPill()
    {
        return _Pill($this->label())->class($this->classes())->class('text-white');
    }
}
