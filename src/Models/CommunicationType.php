<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\SmsCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\EmailCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\DatabaseCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\TaskCommunicationHandler;

enum CommunicationType: int 
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case EMAIL = 1;
    case SMS = 2;
    case DATABASE = 3;
    case TASK = 4;

    public function label()
    {
        return match ($this) {
            self::EMAIL => __('communications.communication-email'),
            self::SMS => __('communications.communication-sms'),
            self::TASK => __('communications.communication-task'),
            self::DATABASE => __('communications.communication-dashboard'),
        };
    }

    public static function labelFor(int|string|null $channel): string
    {
        return self::tryFrom((int) $channel)?->label() ?? '—';
    }

    /** Soft per-channel pill classes (light tint + matching text) so channels read at a glance. */
    public function color(): string
    {
        return match ($this) {
            self::EMAIL => 'bg-blue-100 text-blue-700',
            self::SMS => 'bg-amber-100 text-amber-700',
            self::DATABASE => 'bg-emerald-100 text-emerald-700',
            self::TASK => 'bg-gray-100 text-gray-700',
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
            self::TASK => new TaskCommunicationHandler($communication, $this),
        };
    }
}