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

    public function person()
    {
        return $this->belongsTo(static::personClass(), 'person_id');
    }

    public function recipient()
    {
        return $this->morphTo();
    }

    // HELPERS
    /**
     * Resolve the CRM Person class without making the package hard-depend on condoedge/crm.
     * Falls back to the bare table name when the facade is unavailable.
     */
    protected static function personClass(): string
    {
        if (class_exists(\Condoedge\Crm\Facades\PersonModel::class)) {
            return \Condoedge\Crm\Facades\PersonModel::getClass();
        }

        return Model::class;
    }
}
