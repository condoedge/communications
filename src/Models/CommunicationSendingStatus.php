<?php

namespace Condoedge\Communications\Models;

enum CommunicationSendingStatus: int
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case PENDING = 1;
    case SENT = 2;
    case FAILED = 3;

    public function label()
    {
        return match ($this) {
            self::PENDING => __('communications.pending'),
            self::SENT => __('communications.sent'),
            self::FAILED => __('communications.failed'),
        };
    }

    public function classes()
    {
        return match ($this) {
            self::PENDING => 'bg-warning',
            self::SENT => 'bg-info',
            self::FAILED => 'bg-danger',
        };
    }
}