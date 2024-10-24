<?php

namespace Condoedge\Communications\Components;

use Kompo\Auth\Common\Table;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Components\CommunicationTemplateForm;

class CommunicationsList extends Table
{
    public $id = 'communications-list';

    public function top()
    {
        return _FlexBetween(
            _Html('communications')->miniTitle(),

            _Flex(
                _Button('translate.check-templates-triggers')->selfGet('checkDefaultTemplates')->inModal(),
                _Button('translate.create-default-templates')->selfPost('createDefaultTemplates')->alert('translate.created-templates')->refresh(),
                _Button('form')->selfGet('communicationTemplateForm')->inModal(),
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
            _Th('translate.date'),
            _Th('translate.title'),
            _Th('translate.trigger'),
            _Th('translate.number-of-ways'),
            _Th('')->class('w-8'),
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
            _Delete($communicationGroup),
        )->selfGet('communicationTemplateForm', ['communicationTemplateGroup' => $communicationGroup->id])->inModal();
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
            _Html($triggers->isEmpty() ? 'translate.all-templates-are-set' : 'translate.some-templates-are-missing')
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