<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

abstract class AbstractCommunicationHandler
{
    protected CommunicationTemplate $communication;
    protected $type;

    public function __construct(?CommunicationTemplate $communication, ?CommunicationType $type)
    {
        $this->communication = $communication ?? new CommunicationTemplate;
        $this->type = $type;
    }

    /**
     * Return the interface that the communicables should implement
     * @return \Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable
     */
    abstract public function communicableInterface();

    // NOTIFICATION

    /**
     * The concrete implementation of the notification method to send the communication to the communicables
     * @param array<\Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable> $communicables
     * @param array<string, mixed> $params
     * @return void
     */
    abstract public function notifyCommunicables(array $communicables, $params = []);

    /**
     * Filter the communicables and notify them using `notifyCommunicables` method
     * @param array|\Illuminate\Database\Eloquent\Collection $communicables
     * @param mixed $params
     * @return void
     */
    final public function notify(array|Collection $communicables, $params = [])
    {
        $communicableInterface = $this->communicableInterface();

        $communicables = collect($communicables)->filter(function ($communicable) use ($communicableInterface) {
            $condition = in_array($communicableInterface, class_implements($communicable));

            if (!$condition) Log::warning('Communicable does not implement the required interface: ' . $communicableInterface);

            return $condition;
        });

        $this->notifyCommunicables($communicables->all(), $params);
    }

    // COMMUNICATION SAVING

    /**
     * Save the communication with the given attributes
     * @param number $groupId
     * @param array<string, mixed> $attributes
     * @return void
     */
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

    /**
     * Return the form inputs for the communication to integrate into the `getForm` method
     * @return array<\Kompo\Elements\Element>
     */
    public function formInputs()
    {
        $attrs = $this->communication->getAttributes();

        return [
            _Translatable('Subject')->name('subject', false)->default(json_decode($attrs['subject'] ?? '{}')),
            _EnhancedEditor('Content')->withoutHeight()->name('content', false)->default(json_decode($attrs['content'] ?? '{}')),
        ];
    }

    /**
     * Return the form for the communication
     * @return \Kompo\Rows
     */
    final public function getForm()
    {
        return _Rows(
            _Rows($this->formInputs()),

            _Hidden()->name('previous_communication_type', false)->value($this->type),
        );
    }

    // VALIDATION

    /**
     * Check if the communication is valid to save. It should also return true if the communication has a valid data to be a draft 
     * @param mixed $attributes
     * @return bool
     */
    public function validToSave($attributes = [])
    {
        return (bool) collect($this->requiredAttributes())->first(function ($attribute) use ($attributes) {
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    /**
     * Return if the communication is a draft keeping in mind the required attributes
     * @param mixed $attributes
     * @return bool
     */
    public function isDraft($attributes = [])
    {
        return !collect($this->requiredAttributes())->every(function ($attribute) use ($attributes) {
            return $attributes[$attribute] ?? $this->communication->$attribute;
        });
    }

    /**
     * Return the required attributes for the communication to be valid (not draft)
     * @return string[]
     */
    protected function requiredAttributes()
    {
        return ['subject', 'content'];
    }
}
