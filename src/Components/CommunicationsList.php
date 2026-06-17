<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Components\CommunicationTemplateForm;
use Condoedge\Communications\Services\TemplateSeeding\TemplateSeedingServiceContract;
use Condoedge\Utils\Kompo\Common\WhiteTable;

class CommunicationsList extends WhiteTable
{
    public $id = 'communications-list';

    public function top()
    {
        return _FlexBetween(
            _Html('communications.communications')->class('text-2xl font-semibold'),

            _Flex(
                // Default-template seeding is now handled by `php artisan communications:seed-templates`.
                // Buttons preserved as comments so the UI flow can be re-enabled by uncommenting; the
                // underlying methods delegate to TemplateSeedingService for parity with the command.
                // _Button('communications.check-templates-triggers')->Outlined()->selfGet('checkDefaultTemplates')->inModal(),
                // _Button('communications.create-default-templates')->Outlined()->selfPost('createDefaultTemplates')->alert('communications.template-created')->refresh(),
                _Button('communications.create-communication')->selfGet('communicationTemplateForm')->inModal()
                    ->checkAuthWrite('communications'),
            )->class('gap-3'),
        )->class('mb-4');
    }

    public function query()
    {
        return CommunicationTemplateGroup::latest()
            ->where(fn($q) => $q->whereNull('direct_usage')->orWhere('direct_usage', false));
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
            $this->getSendingTypesList($communicationGroup),
            _Flex(
                _Delete($communicationGroup)->checkAuthWrite('communications'),
                _TripleDotsDropdown(
                    !method_exists($communicationGroup->trigger, 'manuallyForm') ? null
                        : _Link('communications.manual-trigger')->class('px-2 py-1')->selfGet('getManuallyForm', ['trigger' => $trigger, 'communication_id' => $communicationGroup->id])->inModal()
                            ->checkAuthWrite('communications'),
                ),
            )->class('gap-1'),
        )->selfGet('communicationTemplateForm', ['communicationTemplateGroup' => $communicationGroup->id])->inModal();
    }

    protected function getSendingTypesList($communicationGroup)
    {
        $communicationTemplates = $communicationGroup->communicationTemplates;

        return _Flex(
            collect($communicationTemplates)->map(function ($template, $key) use ($communicationTemplates) {
                $isValid = !$template->is_draft;
                $last = $key === $communicationTemplates->keys()->last();

                return _Flex(
                    _Html($template->type->label())->class($isValid ? '' : 'text-warning')
                        ->when(!$isValid, fn ($el) => $el->balloon('communications.complete-all-info')),
                    $last ? null : _Html(',')->class('mr-1'),
                );
            }),
        );
    }

    public function getManuallyForm()
    {
        abort_unless(checkAuthPermission('communications', \Kompo\Auth\Models\Teams\PermissionTypeEnum::WRITE), 403);

        return request('trigger')::manuallyForm(request('communication_id'));
    }

    public function communicationTemplateForm($groupId = null)
	{
		abort_unless(checkAuthPermission('communications', \Kompo\Auth\Models\Teams\PermissionTypeEnum::WRITE), 403);

		return new CommunicationTemplateForm($groupId);
	}

    public function checkDefaultTemplates()
    {
        $missing = app(TemplateSeedingServiceContract::class)->getMissingTriggers()
            ->map(fn ($trigger) => _Html($trigger::getName())->class('text-danger'));

        return _Rows(
            _Html($missing->isEmpty() ? 'communications.all-templates-are-set' : 'communications.some-templates-are-missing')
                ->class('text-lg')
                ->class($missing->isEmpty() ? 'text-positive' : 'text-black'),
            ...$missing,
        )->class('p-4');
    }

    public function createDefaultTemplates()
    {
        app(TemplateSeedingServiceContract::class)->seedAll();
    }
}