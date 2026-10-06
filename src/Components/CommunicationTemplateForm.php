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
        $channel = $this->defaultChannel($this->model->trigger);

        return _Rows(
            $this->mainFields(),

            !$this->showsChannels() ? _Rows(
                _Html('communications.fill-main-data-help')->class('text-center mb-4'),
            )  : _Panel(
                $this->getFormsTypes(
                    $channel->handler($this->model->findCommunicationTemplate($channel->value))->getForm($this->model->trigger, $this->context),
                )
            )->id('communication-type-form-container'),

            _SubmitButton($this->submitButtonMessage())->refresh('communications-list')
                ->when($this->showsChannels(), fn($el) => $el->closeModal()),
        );
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

    public function saveAndGetNewForm()
    {
        $communicationType = request('communication_type');
        $trigger = request('trigger');
        $previousCommunicationType = request('previous_communication_type');

        if ($previousCommunicationType) {
            $this->savePreviousCommunication($previousCommunicationType);
        } else if (!$communicationType) { // If there is no previous communication type, we are probably coming from the trigger select, so we set a default
            $communicationType = $this->defaultChannel($trigger ?? $this->model->trigger)->value;
        }

        if (!$communicationType) {
            return _Html('communications.select-communication-type-to-edit')->class('text-center');
        }

        $oldCommunication = $this->model->findCommunicationTemplate($communicationType);

        $communicationType = CommunicationType::from($communicationType);

        return $communicationType->handler($oldCommunication)->getForm($trigger ?? $this->model->trigger, $this->context);
    }

    public function saveAndGetNewFormTypes()
    {
        $handler = $this->saveAndGetNewForm();

        return $this->getFormsTypes($handler);
    }

    public function getFormsTypes($handler = null)
    {
        $trigger = $this->model->trigger ?? request('trigger');

        return _Rows(
            _ButtonGroup()->options(
                    $this->channelsFor($trigger)
                        ->mapWithKeys(fn($t) => [$t->value => $t->label()])
                        ->all()
                )
                    ->optionClass('p-2 text-center')
                    ->name('communication_type', false)
                    ->default($this->defaultChannel($trigger)->value)
                    ->selfPost('saveAndGetNewForm')
                    ->withAllFormValues()
                    ->inPanel('communication-type-form'),

                _Panel(
                    $handler
                )->id(id: 'communication-type-form')->class('mb-6'),
        );
    }

    protected function channelsFor($trigger)
    {
        return collect(CommunicationType::cases())
            ->reject(fn($t) => !$t->enabled())
            ->filter(fn($t) => !$trigger || !method_exists($trigger, 'acceptsChannel') || $trigger::acceptsChannel($t))
            ->values();
    }

    /** EMAIL unless the trigger refuses it — a database-only trigger must not open on a form it cannot use. */
    protected function defaultChannel($trigger): CommunicationType
    {
        $channels = $this->channelsFor($trigger);

        return $channels->first(fn($t) => $t === CommunicationType::EMAIL) ?? $channels->first() ?? CommunicationType::EMAIL;
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