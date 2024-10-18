<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Facades\ContentReplacer;
use Illuminate\Database\Eloquent\Collection;
use Kompo\Auth\Models\Model;

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

    public function group()
    {
        return $this->belongsTo(CommunicationTemplateGroup::class, 'template_group_id');
    }

    public function communicationSendings()
    {
        return $this->hasMany(CommunicationSending::class);
    }

    public function getHandler()
    {
        return $this->type->handler($this);
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
    
    public function notify(array|Collection $communicables, $params = []) 
    {
        return $this->getHandler()->notify($communicables, $params);
    }

    public function delete()
    {
        if ($this->communicationSendings()->count()) {
            abort(403, 'translate.cannot-delete-a-communication-with-sendings');
        }

        NotificationTemplate::where('communication_id', $this->id)->delete();

        return parent::delete();
    }
}