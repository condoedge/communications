<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\MessageContentReplacer;
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

    public function getHandler()
    {
        return $this->type->handler($this);
    }

    public function getParsedContent($params = [])
    {
        $messageReplacer = new MessageContentReplacer();
        $messageReplacer->setText($this->content);
        $messageReplacer->injectContext($params);

        return $messageReplacer->replace($this->type);
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

}