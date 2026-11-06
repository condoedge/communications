<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\SmsCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\EmailCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\DatabaseCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\TaskCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\PushCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\PushSender;
use Condoedge\Utils\Facades\GlobalConfig;

enum CommunicationType: int 
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    case EMAIL = 1;
    case SMS = 2;
    case DATABASE = 3;
    case TASK = 4;
    case PUSH = 5;

    public function label()
    {
        return match ($this) {
            self::EMAIL => __('communications.communication-email'),
            self::SMS => __('communications.communication-sms'),
            self::TASK => __('communications.communication-task'),
            self::DATABASE => __('communications.communication-dashboard'),
            self::PUSH => __('communications.communication-push'),
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
            self::PUSH => 'bg-purple-100 text-purple-700',
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
            self::PUSH => new PushCommunicationHandler($communication, $this),
        };
    }

    public function enabledKey(): string
    {
        return match ($this) {
            self::EMAIL => 'communications_channels.email.enabled',
            self::SMS => 'communications_channels.sms.enabled',
            self::DATABASE => 'communications_channels.database.enabled',
            self::TASK => 'communications_channels.task.enabled',
            self::PUSH => 'communications_channels.push.enabled',
        };
    }

    /**
     * Whether communications on this channel are sent. PUSH also needs the host app
     * to bind a PushSender: an app without a mobile app never gets the channel.
     */
    public function enabled(): bool
    {
        if ($this === self::PUSH && !app()->bound(PushSender::class)) {
            return false;
        }

        return GlobalConfig::get($this->enabledKey(), true);
    }

    /**
     * Whether the channel is offered when editing templates. PUSH stays reserved to
     * super admins while it is being tested, until `communications_channels.push.public`
     * is turned on.
     */
    public function availableInEditor(): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        if ($this === self::PUSH) {
            return isSuperAdmin() || GlobalConfig::get('communications_channels.push.public', false);
        }

        return true;
    }
}