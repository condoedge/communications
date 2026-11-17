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
    case SKIPPED = 8;

    /**
     * The statuses a row can hold: the engagement ones only for a host that records them.
     *
     * @return self[]
     */
    public static function writableCases(): array
    {
        $cases = [self::PENDING, self::SENT, self::FAILED, self::SKIPPED];

        return config('kompo-communications.engagement-stats')
            ? [...$cases, self::DELIVERED, self::OPENED, self::CLICKED, self::BOUNCED]
            : $cases;
    }

    /** Filter options limited to writableCases(): value => label. */
    public static function filterOptions(): array
    {
        return collect(self::writableCases())
            ->mapWithKeys(fn ($status) => [$status->value => $status->label()])
            ->all();
    }

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
            self::SKIPPED => __('communications.skipped'),
        };
    }

    public function classes()
    {
        return match ($this) {
            self::SENT, self::DELIVERED => 'bg-info',
            self::OPENED, self::CLICKED => 'bg-positive',
            self::FAILED, self::BOUNCED => 'bg-danger',
            self::PENDING => 'bg-warning',
            self::SKIPPED => 'bg-gray-400',
        };
    }

    public function statusPill()
    {
        return _Pill($this->label())->class($this->classes())->class('text-white');
    }
}
