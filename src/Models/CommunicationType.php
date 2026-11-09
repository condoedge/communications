<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\SmsCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\EmailCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\DatabaseCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\TaskCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\PushCommunicationHandler;
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

    /** The channel's segment in config keys and on the command line: `communications_channels.{key}.*`. */
    public function configKey(): string
    {
        return strtolower($this->name);
    }

    public function enabledKey(): string
    {
        return "communications_channels.{$this->configKey()}.enabled";
    }

    /** Whether the channel is switched on. Config only: what the channel needs to deliver is sendable()'s question. */
    public function enabled(): bool
    {
        return (bool) GlobalConfig::get($this->enabledKey(), true);
    }

    /**
     * Whether a communication on this channel can go out in this app: switched on, and its handler
     * has what it needs. The handler answers the second half, so a channel with a host-side
     * dependency (push and its sender) needs no special case here.
     */
    public function sendable(): bool
    {
        return $this->enabled() && $this->handler(null)->isConfigured();
    }

    /** From what an operator types: the case value ("5") or its name in any case ("push"). */
    public static function tryFromInput(?string $input): ?self
    {
        $input = trim((string) $input);

        if (ctype_digit($input)) {
            return self::tryFrom((int) $input);
        }

        return collect(self::cases())->first(fn (self $type) => $type->configKey() === strtolower($input));
    }
}
