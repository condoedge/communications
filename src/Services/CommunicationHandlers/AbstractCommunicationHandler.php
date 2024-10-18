<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

abstract class AbstractCommunicationHandler
{
    protected $communication;
    protected $type;

    public function __construct($communication, CommunicationType $type)
    {
        $this->communication = $communication ?? new CommunicationTemplate;
        $this->type = $type;
    }
    
    public function formInputs()
    {
        $attrs = $this->communication->getAttributes();

        return [
            _Translatable('Subject')->name('subject', false)->default(json_decode($attrs['subject'] ?? '{}')),
            _CKEditorWithVars('Content')->withoutHeight()->name('content', false)->default(json_decode($attrs['content'] ?? '{}')),
        ];
    }

    final public function getForm()
    {
        return _Rows(
            _Rows($this->formInputs()),

            _Hidden()->name('previous_communication_type', false)->value($this->type),
        );
    }

    public function save($groupId = null, $attributes = [])
    {
        $this->communication->type = $this->type;
        $this->communication->template_group_id = $groupId;

        $this->communication->subject = $attributes['subject'] ?? null;
        $this->communication->content = $attributes['content'] ?? null;

        if ($this->validToSave($attributes)) {
            $this->communication->is_draft = $this->isDraft($attributes) ? 1 : 0;

            $this->communication->save();
        }

        request()->replace([]);
    }

    public function validToSave($attributes = [])
    {
        return (boolean) collect($this->requiredAttributes())->first(function($attribute) use ($attributes) {
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    public function isDraft($attributes = []) 
    {
        return !collect($this->requiredAttributes())->every(function($attribute) use ($attributes){
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    public function requiredAttributes()
    {
        return ['subject', 'content'];
    }

    abstract public function communicableInterface();
    abstract public function notifyCommunicables(array $communicables, $params = []);

    final public function notify(array|Collection $communicables, $params = []) 
    {
        $communicableInterface = $this->communicableInterface();

        $communicables = collect($communicables)->filter(function($communicable) use ($communicableInterface) {
            $condition = in_array($communicableInterface, class_implements($communicable));

            if(!$condition) Log::warning('Communicable does not implement the required interface: ' . $communicableInterface);

            return $condition;
        });

        $this->notifyCommunicables($communicables->all(), $params);
    }
}