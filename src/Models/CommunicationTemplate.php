<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Facades\ContentReplacer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Condoedge\Utils\Models\Model;

class CommunicationTemplate extends Model
{
    use \Kompo\Database\HasTranslations;

    protected $casts = [
        "type" => CommunicationType::class,
    ];

    protected $translatable = [
        'subject',
        'content',
    ];

    public function save(array $options = [])
    {
        $this->is_draft = $this->is_draft ?? ($this->getHandler()->isDraft() ? 1 : 0);

        return parent::save($options);
    }

    // RELATIONSHIPS
    public function group()
    {
        return $this->belongsTo(CommunicationTemplateGroup::class, 'template_group_id');
    }

    public function communicationSendings()
    {
        return $this->hasMany(CommunicationSending::class);
    }

    // CALCULATED FIELDS
    public function getHandler()
    {
        return $this->type->handler($this);
    }

    public function getParsedTitle($params = [])
    {
        return ContentReplacer::setText($this->subject)
            ->injectContext($params)
            ->replace($this->type);
    }

    public function getParsedContent($params = [])
    {
        return ContentReplacer::setText($this->content)
            ->injectContext($params)
            ->replace($this->type);
    }

    // SCOPES
    public function scopeIsValid($query)
    {
        return $query->where('is_draft', 0);
    }
    
    // ACTIONS
    public function notify(array|Collection $communicables, $params = []) 
    {
        $communicationSending = CommunicationSending::createOneForCommunicationTemplate($this, $communicables, $params);

        try {
            $this->getHandler()->notify($communicables, $params);
            $communicationSending->status = CommunicationSendingStatus::SENT;
        } catch (\Exception $e) {
            Log::warning("Error sending communication: " . $e->getMessage(), $e->getTrace());
            $communicationSending->status = CommunicationSendingStatus::FAILED;
        } finally {
            $communicationSending->save();
        }
    }

    public function delete()
    {
        if ($this->communicationSendings()->count()) {
            abort(403, 'error.cannot-delete-a-communication-with-sendings');
        }

        NotificationTemplate::where('communication_id', $this->id)->delete();

        return parent::delete();
    }
}