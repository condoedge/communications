<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Triggers\ManualTrigger;
use Condoedge\Utils\Kompo\Common\Modal;

class CommunicationTemplateForm extends Modal
{
    use EditsCommunicationChannels;

    public $model = CommunicationTemplateGroup::class;

    protected $_Title = 'communications.manage-communication';

    public $style = 'max-height: 95vh;';
    public $class = 'overflow-y-auto mini-scroll max-w-2xl';

    public $refreshAfterSubmit = true;
    public $noHeaderButtons = true;

    protected $directUsage = false;

    protected $communicableType;
    protected $communicablesIds;

    protected $context;
    public function created()
    {
        $this->directUsage = $this->prop('direct_usage');
        $this->communicableType = $this->prop('communicable_type');
        $this->communicablesIds = $this->prop('communicables_ids');

        if ($this->directUsage) {
            $this->refreshAfterSubmit = false;
            $this->_Title = 'communications.send-communication';
        }

        $this->context = [
            'communicable_type' => $this->communicableType,
            'communicables_ids' => $this->communicablesIds,
        ];
    }

    public function afterSave()
    {
        if (request('communication_type')) {
            $this->savePreviousCommunication(request('communication_type'));
        }

        if ($this->directUsage) {
            event(new ManualTrigger(
                [$this->model->id],
                $this->communicableType,
                explode(',', $this->communicablesIds)
            ));
        }
    }

    public function body()
    {
        return _Rows(
            $this->mainFields(),

            !$this->showsChannels() ? _Rows(
                _Html('communications.fill-main-data-help')->class('text-center mb-4'),
            )  : $this->channelsSection(),

            _SubmitButton($this->submitButtonMessage())->refresh('communications-list')
                ->when($this->showsChannels(), fn($el) => $el->closeModal()),
        );
    }

    protected function channelsGroup(): CommunicationTemplateGroup
    {
        return $this->model;
    }

    protected function channelsContext(): array
    {
        return $this->context;
    }

    /**
     * Whether the editor is on its channels screen. A group picked by hand needs its trigger saved
     * first (the "Next" step); an extension that already knows the trigger goes straight there.
     */
    protected function showsChannels(): bool
    {
        return (bool) $this->model->id;
    }

    /** Title + trigger. An extension that already knows its trigger overrides only this. */
    protected function mainFields()
    {
        if ($this->directUsage) {
            return _Rows(
                _Hidden()->name('title')->value($this->model->title),
                _Hidden()->name('trigger')->value($this->model->trigger),
            );
        }

        if ($this->model->id) {
            // Editing an existing group: the trigger is already fixed, so keep it hidden.
            return _Rows(
                _Input('Name')->name('title')->value($this->model->title),
                _Hidden()->name('trigger')->value($this->model->trigger),
            );
        }

        return _Rows(
            _Input('Name')->name('title'),

            _Select('trigger')->name('trigger')
                ->config(['floatingOptions' => true])
                ->options(collect(CommunicationTemplateGroup::getTriggers())->mapWithKeys(fn($trigger) => [$trigger => $trigger::getName()]))
                ->selfPost('saveAndGetNewFormTypes')
                ->inPanel('communication-type-form')
                ->panelLoading('communication-type-form'),
        );
    }

    protected function submitButtonMessage()
    {
        if ($this->directUsage) {
            return 'communications.send';
        }

        return !$this->showsChannels() ? 'communication.next' : 'generic.save';
    }

    public function rules()
    {
        return [
            'title' => 'required',
            'trigger' => 'required',
        ];
    }
}
