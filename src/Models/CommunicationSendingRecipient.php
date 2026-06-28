<?php

namespace Condoedge\Communications\Models;

use Condoedge\Utils\Models\Model;

class CommunicationSendingRecipient extends Model
{
    protected $casts = [
        'status' => CommunicationSendingRecipientStatus::class,
    ];

    // RELATIONSHIPS
    public function communicationSending()
    {
        return $this->belongsTo(CommunicationSending::class);
    }

    public function recipient()
    {
        return $this->morphTo();
    }
}
