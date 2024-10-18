<?php

namespace Condoedge\Communications\Models;

use Kompo\Auth\Models\Model;

class CommunicationSending extends Model
{
    public function communicationTemplate()
    {
        return $this->belongsTo(CommunicationTemplate::class);
    }
}