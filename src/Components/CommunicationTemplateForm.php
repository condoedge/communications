<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Triggers\ManualTrigger;
use Condoedge\Utils\Kompo\Common\Modal;

class CommunicationTemplateForm extends Modal
{
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
            $this->directUsage ? _Rows(
                _Hidden()->name('title')->value($this->model->title),
                _Hidden()->name('trigger')->value($this->model->trigger),
            ) : 
            
            _Rows(
                _Input('Name')->name('title'),

                _Select('trigger')->name('trigger')
                    ->options(collect(CommunicationTemplateGroup::getTriggers())->mapWithKeys(fn($trigger) => [$trigger => $trigger::getName()]))
                    ->selfPost('saveAndGetNewForm')
                    ->inPanel('communication-type-form')
                    ->panelLoading('communication-type-form'),
            ),


            !$this->model->id ? _Rows(
                _Html('fill the main data to complete the other messages')->class('text-center mb-4'),
            )  : _Rows(
                _ButtonGroup()->options(CommunicationType::optionsWithLabels())
                    ->optionClass('p-2 text-center')
                    ->name('communication_type', false)
                    ->default(CommunicationType::EMAIL->value)
                    ->selfPost('saveAndGetNewForm')
                    ->withAllFormValues()
                    ->inPanel('communication-type-form'),

                _Panel(
                    CommunicationType::EMAIL->handler($this->model->findCommunicationTemplate(CommunicationType::EMAIL->value))->getForm($this->model->trigger, $this->context),
                )->id(id: 'communication-type-form')->class('mb-6'),
            ),

            _SubmitButton($this->submitButtonMessage())->refresh('communications-list')
                ->when($this->model->id, fn($el) => $el->closeModal()),
        );
    }

    protected function submitButtonMessage()
    {
        if ($this->directUsage) {
            return 'communications.send';
        }

        return !$this->model->id ? 'communication.next' : 'generic.save';
    }

    public function saveAndGetNewForm()
    {
        $communicationType = request('communication_type');
        $trigger = request('trigger');
        $previousCommunicationType = request('previous_communication_type');

        if ($previousCommunicationType) {
            $this->savePreviousCommunication($previousCommunicationType);
        } else if (!$communicationType) { // If there is no previous communication type, we are probably coming from the trigger select, so we set a default
            $communicationType = CommunicationType::EMAIL->value;
        }

        if (!$communicationType) {
            return _Html('you must select one to see the editor')->class('text-center');
        }

        $oldCommunication = $this->model->findCommunicationTemplate($communicationType);

        $communicationType = CommunicationType::from($communicationType);

        return $communicationType->handler($oldCommunication)->getForm($trigger ?? $this->model->trigger, $this->context);
    }

    protected function savePreviousCommunication($communicationType)
    {
        if(!$communicationType) return null;

        $oldCommunication = $this->model->findCommunicationTemplate($communicationType);

        $previousCommunicationType = CommunicationType::from($communicationType);
        $previousCommunicationType->handler($oldCommunication)->save( $this->model->id, request()->all());
    }

    public function rules()
    {
        return [
            'title' => 'required',
            'trigger' => 'required',
        ];
    }
}