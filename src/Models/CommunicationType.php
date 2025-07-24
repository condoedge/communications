<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\SmsCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\EmailCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\DatabaseCommunicationHandler;

enum CommunicationType: int 
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case EMAIL = 1;
    case SMS = 2;
    case DATABASE = 3;

    public function label()
    {
        return match ($this) {
            self::EMAIL => __('communications.communication-email'),
            self::SMS => __('communications.communication-sms'),
            self::DATABASE => __('communications.communication-dashboard'),
        };
    }

    /**
     * Summary of handler
     * @param mixed \Condoedge\Communications\Models\Monitoring\CommunicationTemplate $communication Communication
     * @return AbstractCommunicationHandler
     */
    public function handler(?CommunicationTemplate $communication): AbstractCommunicationHandler
    {
        return match ($this) {
            self::EMAIL => new EmailCommunicationHandler($communication, $this),
            self::SMS => new SmsCommunicationHandler($communication, $this),
            self::DATABASE => new DatabaseCommunicationHandler($communication, $this),
        };
    }
}