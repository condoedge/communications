<?php

namespace Condoedge\Communications\Models;

enum CommunicationSendingStatus: int
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case PENDING = 1;
    case SENT = 2;
    case FAILED = 3;
    case PARTIAL = 4;
    case SKIPPED = 5;

    public function label()
    {
        return match ($this) {
            self::PENDING => __('communications.pending'),
            self::SENT => __('communications.sent'),
            self::FAILED => __('communications.failed'),
            self::PARTIAL => __('communications.partially-sent'),
            self::SKIPPED => __('communications.skipped'),
        };
    }

    public function classes()
    {
        return match ($this) {
            self::PENDING => 'bg-warning',
            self::SENT => 'bg-info',
            self::FAILED => 'bg-danger',
            self::PARTIAL => 'bg-warning',
            self::SKIPPED => 'bg-gray-400',
        };
    }
}