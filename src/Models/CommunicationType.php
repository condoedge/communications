<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Models\Monitoring\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Models\Monitoring\CommunicationHandlers\DatabaseCommunicationHandler;
use Condoedge\Communications\Models\Monitoring\CommunicationHandlers\EmailCommunicationHandler;
use Condoedge\Communications\Models\Monitoring\CommunicationHandlers\SmsCommunicationHandler;
use Condoedge\Communications\Models\Monitoring\CommunicationTemplate;

enum CommunicationType: int 
{
    use \Kompo\Auth\Models\Traits\EnumKompo;

    case EMAIL = 1;
    case SMS = 2;
    case DATABASE = 3;

    public function label()
    {
        return match ($this) {
            self::EMAIL => __('translate.communication-email'),
            self::SMS => __('translate.communication-sms'),
            self::DATABASE => __('translate.communication-database'),
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