<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationSendingStatus;
use Kompo\Auth\Common\Table;

class CommunicationSendingsList extends Table
{
    public function top()
    {
        return _FlexEnd(
            _Select()->name('status')->placeholder('translate.filter-by-status')
                ->options(CommunicationSendingStatus::optionsWithLabels())
                ->filter(),
        );
    }

    public function query()
    {
        return CommunicationSending::query()
            ->when(request('status'), fn($query) => $query->where('status', request('status')));
    }

    public function headers()
    {
        return [
            _Th('translate.sent-at'),
            _Th('translate.communication-template'),
            _Th('translate.communication-event'),
            _Th('translate.via'),
            _Th('translate.status'),
        ];
    }

    public function render($communicationSending)
    {
        $communicationTemplate = $communicationSending->communicationTemplate;
        $communicationTemplateGroup = $communicationTemplate->group;

        return _Rows(
            _Html($communicationSending->sent_at->format('Y-m-d H:i')),
            _Html($communicationTemplateGroup->title),
            _Html($communicationTemplateGroup->trigger::getName()),
            _Html($communicationTemplate->type->label()),
            $communicationSending->statusPill(),
        );
    }
}