<?php

namespace Condoedge\Communications\Components;

use Condoedge\Utils\Kompo\Common\Table;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Components\CommunicationTemplateForm;

class CommunicationsList extends Table
{
    public $id = 'communications-list';

    public function top()
    {
        return _FlexBetween(
            _Html('communications.communications')->miniTitle(),

            _Flex(
                _Button('communications.check-templates-triggers')->Outlined()->selfGet('checkDefaultTemplates')->inModal(),
                _Button('communications.create-default-templates')->Outlined()->selfPost('createDefaultTemplates')->alert('communications.template-created')->refresh(),
                _Button('communications.Create communication')->selfGet('communicationTemplateForm')->inModal(),
            )->class('gap-3'),
        );
    }

    public function query()
    {
        return CommunicationTemplateGroup::latest();
    }

    public function headers()
    {
        return [
            _Th('communications.date'),
            _Th('communications.title'),
            _Th('communications.trigger'),
            _Th('communications.number-of-ways'),
            _Th('')->class('w-16'),
        ];
    }

    public function render($communicationGroup)
    {
        $trigger = $communicationGroup->trigger;

        return _TableRow(
            _Html($communicationGroup->created_at->format('d/m/Y H:i:s')),
            _Html($communicationGroup->title),
            _Html(!$trigger ? '-' : $trigger::getName()),
            _Html($communicationGroup->communicationTemplates()->isValid()->pluck('type')->map(fn($type) => $type->label())->implode(', ')),
            _Flex(
                _Delete($communicationGroup),
                _TripleDotsDropdown(
                    !method_exists($communicationGroup->trigger, 'manuallyForm') ? null
                        : _Link('communications.manual-trigger')->class('px-2 py-1')->selfGet('getManuallyForm', ['trigger' => $trigger, 'communication_id' => $communicationGroup->id])->inModal(),
                ),
            )->class('gap-1'),
        )->selfGet('communicationTemplateForm', ['communicationTemplateGroup' => $communicationGroup->id])->inModal();
    }

    public function getManuallyForm()
    {
        return request('trigger')::manuallyForm(request('communication_id'));
    }

    public function communicationTemplateForm($groupId = null)
	{
		return new CommunicationTemplateForm($groupId);
	}

    public function checkDefaultTemplates()
    {
        $triggers = collect(CommunicationTemplateGroup::getTriggers())->filter(function ($trigger) {
            return !CommunicationTemplateGroup::where('trigger', $trigger)->exists();
        })->map(function ($trigger) {
            return _Html($trigger::getName())->class('text-danger');
        });

        return _Rows(
            _Html($triggers->isEmpty() ? 'communications.all-templates-are-set' : 'communications.some-templates-are-missing')
                ->class('text-lg')
                ->class($triggers->isEmpty() ? 'text-positive' : 'text-black'), 
            ...$triggers
        )->class('p-4');
    }

    public function createDefaultTemplates()
    {
        collect(CommunicationTemplateGroup::getTriggers())->each(function ($trigger) {
            if (CommunicationTemplateGroup::where('trigger', $trigger)->exists()) return;

            CommunicationTemplateGroup::createForTrigger($trigger);
        });
    }
}