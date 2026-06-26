<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Utils\Kompo\Common\Form;

/**
 * Read-only drill-down for one send (header + its recipients). Reached from a Send-log row.
 */
class CommunicationSendingModal extends Form
{
    public $class = 'p-6 max-w-2xl';

    protected $sending;

    public function created()
    {
        $this->sending = CommunicationSending::with('recipients.person')->findOrFail($this->prop('id') ?? $this->model->id);
    }

    public function render()
    {
        return _Rows(
            _TitleMini(CommunicationTemplateGroup::triggerName($this->sending->trigger)),
            _FlexBetween(
                $this->headerStat('communications.channel', CommunicationType::labelFor($this->sending->channel)),
                $this->headerStat('communications.sent-at', communicationDateTime($this->sending->sent_at, __('communications.never'))),
                $this->headerStat('communications.number-of-ways', (string) $this->sending->recipients->count()),
            )->class('gap-4 mb-4 flex-wrap'),
            _Html('communications.recipient')->class('text-xs text-gray-500 font-semibold mb-1'),
            _Rows(
                $this->sending->recipients->map(fn ($recipient) => _FlexBetween(
                    _Rows(
                        _Html($recipient->person?->full_name ?: '—')->class('font-medium'),
                        _Html($recipient->email ?: '—')->class('text-sm text-gray-500'),
                    ),
                    $this->statusPill($recipient->status),
                )->class('border-b py-2')),
            ),
        );
    }

    protected function headerStat(string $labelKey, string $value)
    {
        return _Rows(
            _Html($labelKey)->class('text-xs text-gray-500'),
            _Html($value)->class('font-semibold'),
        );
    }

    protected function statusPill(?CommunicationSendingRecipientStatus $status)
    {
        return $status ? $status->statusPill() : _Html('—')->class('text-gray-400');
    }
}
