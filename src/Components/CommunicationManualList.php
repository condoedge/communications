<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Triggers\ManualTrigger;
use Condoedge\Utils\Kompo\Common\WhiteTable;

/**
 * Manual communications: messages an admin composes once and fires by hand to chosen recipients
 * (ManualTrigger), as opposed to the internal-event templates that send automatically. Lists the
 * team's reusable manual communications; the one-off direct_usage temps stay hidden.
 *
 * Uses the id the template editor refreshes ('communications-list') so create/edit refresh the list.
 */
class CommunicationManualList extends WhiteTable
{
    public $id = 'communications-list';

    protected $teamId;
    protected $permissionKey = 'Communication';

    public function created()
    {
        $this->teamId = $this->prop('team_id') ?: currentTeamId();
    }

    public function top()
    {
        return _Rows(
            _Html('communications.manual-help')->class('text-sm text-gray-500 mb-3'),
            _FlexBetween(
                _Input()
                    ->placeholder('communications.search-manual')
                    ->name('search', false)
                    ->class('mb-0 w-full max-w-md')
                    ->serverFilter(),
                _Button('communications.create-manual')->icon('plus')
                    ->selfGet('createManual')->inModal()
                    ->checkAuthWrite($this->permissionKey, specificTeamId: $this->teamId),
            )->class('gap-3 items-end'),
        )->class('mb-4');
    }

    public function query()
    {
        $search = mb_strtolower(trim((string) request('search')));

        // The team's own manual communications — many per team (the (team_id, trigger) uniqueness
        // was relaxed for manual); the one-off direct_usage temps stay hidden.
        return CommunicationTemplateGroup::forTrigger(ManualTrigger::class)
            ->where('team_id', $this->teamId)
            ->where(fn ($q) => $q->whereNull('direct_usage')->orWhere('direct_usage', false))
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"]));
    }

    public function headers()
    {
        return [
            _Th('communications.name'),
            _Th('communications.channels'),
            _Th('communications.last-sent'),
            _Th()->class('w-12'),
        ];
    }

    public function render($group)
    {
        return _TableRow(
            _Html($group->title)->class('font-medium'),
            $this->channelsCell($group),
            _Html(communicationDateTime($this->lastSent($group), __('communications.never'))),
            $this->actionsCell($group),
        );
    }

    protected function channelsCell(CommunicationTemplateGroup $group)
    {
        $templates = $group->communicationTemplates;

        if ($templates->isEmpty()) {
            return _Html('—')->class('text-gray-400');
        }

        return _Flex(
            $templates->map(fn ($template) => _Pill($template->type->label())
                ->class('text-xs font-medium')
                ->class($template->is_draft ? 'bg-red-100 text-red-700' : $template->type->color())),
        )->class('gap-1 flex-wrap');
    }

    protected function lastSent(CommunicationTemplateGroup $group): ?string
    {
        return CommunicationSending::query()
            ->whereIn('communication_template_id', $group->communicationTemplates->pluck('id'))
            ->max('sent_at');
    }

    protected function actionsCell(CommunicationTemplateGroup $group)
    {
        $links = array_filter([
            _DropdownLink('communications.action-edit')->selfGet('editManual', ['id' => $group->id])->inModal(),
            _DropdownLink('communications.action-send')->selfGet('sendManual', ['id' => $group->id])->inModal(),
            $this->hasSendings($group) ? null : _DropdownLink('communications.action-delete')
                ->selfPost('deleteManual', ['id' => $group->id])->refresh(),
        ]);

        return _TripleDotsDropdown(
            ...collect($links)->map(fn ($link) => $link->checkAuthWrite($this->permissionKey, specificTeamId: $this->teamId)),
        )->class('justify-end');
    }

    protected function hasSendings(CommunicationTemplateGroup $group): bool
    {
        return CommunicationSending::query()
            ->whereIn('communication_template_id', $group->communicationTemplates->pluck('id'))
            ->exists();
    }

    /** Compose a new reusable manual communication, then open the editor on it (trigger is fixed). */
    public function createManual()
    {
        // The team's own reusable manual communication (not a one-off direct_usage temp).
        $group = new CommunicationTemplateGroup();
        $group->trigger = ManualTrigger::class;
        $group->title = __('communications.new-manual-communication');
        $group->team_id = $this->teamId;
        $group->direct_usage = false;
        $group->save();

        return new CommunicationTemplateForm($group->id);
    }

    public function editManual($id)
    {
        return new CommunicationTemplateForm($id);
    }

    /** Fire the manual communication: pick the communicable type + recipients, then send. */
    public function sendManual($id)
    {
        return ManualTrigger::manuallyForm($id);
    }

    public function deleteManual($id)
    {
        $group = CommunicationTemplateGroup::findOrFail($id);

        if (!$this->hasSendings($group)) {
            $group->delete();
        }
    }
}
