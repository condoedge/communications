<?php

namespace Condoedge\Communications\Models;

use Kompo\Auth\Models\Model;

class CommunicationSending extends Model
{
    protected $casts = [
        'status' => CommunicationSendingStatus::class,
    ];

    // RELATIONSHIPS
    public function communicationTemplate()
    {
        return $this->belongsTo(CommunicationTemplate::class);
    }

    // ACTIONS
    public static function createOneForCommunicationTemplate(CommunicationTemplate $communicationTemplate, array $communicables, array $params = [])
    {
        $communicationSending = new static;
        $communicationSending->communication_template_id = $communicationTemplate->id;
        $communicationSending->status = CommunicationSendingStatus::PENDING;
        $communicationSending->save();

        return $communicationSending;
    }

    // ELEMENTS
    public function statusPill()
    {
        return _Pill($this->status->label())->class($this->status->classes())->class('text-white');
    }
}