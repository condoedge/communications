<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\Grouping\TriggerGroupResolverContract;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolution;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract;
use Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateSource;
use Condoedge\Communications\Triggers\ManualTrigger;
use Condoedge\Utils\Kompo\Common\WhiteTable;
use Kompo\Auth\Facades\TeamModel;

/**
 * Inheritance-aware Templates tab: ONE ROW PER configured trigger (minus ManualTrigger and any
 * direct_usage groups). Each row's effective state (OWN / INHERITED / DISABLED / NONE) is resolved
 * for the viewing team through the single EffectiveTemplateResolver — the same decision the runtime
 * send path makes — and the offered actions follow that state.
 *
 * The group column + filter are driven by TriggerGroupResolverContract; the default Null resolver
 * collapses them away.
 */
class CommunicationTemplatesList extends WhiteTable
{
    public $id = 'communication-templates-table';

    protected $isResponsive = true;
    protected $teamId;
    protected $permissionKey = 'Communication';
    protected EffectiveTemplateResolverContract $resolver;
    protected TriggerGroupResolverContract $groups;
    protected bool $hasGroups = false;

    public function created()
    {
        $this->teamId = $this->prop('team_id') ?: currentTeamId();
        $this->resolver = app(EffectiveTemplateResolverContract::class);
        $this->groups = app(TriggerGroupResolverContract::class);
        $this->hasGroups = $this->groups->options()->isNotEmpty();
    }

    public function top()
    {
        return _Rows(
            _Html('communications.internal-events-help')->class('text-sm text-gray-500 mb-3'),
            _FlexEnd(
                _Toggle('translate.show-only-pending-to-configure')
                    ->class('[&>label]:min-w-max')
                    ->name('not_owned', false)
                    ->filter(),
            ),
            _FlexEnd(
                _Input()
                    ->placeholder('communications.search-templates')
                    ->name('search', false)
                    ->class('mb-0 w-full max-w-md')
                    ->serverFilter(),
                !$this->hasGroups ? null : _Select()
                    ->placeholder('communications.all-groups')
                    ->name('group', false)
                    ->options($this->groups->options())
                    ->class('mb-0 w-full max-w-xs')
                    ->serverFilter()
                    ->config(['floatingOptions' => true]),
            )->class('gap-3 flex-wrap '),
        )->class('mb-4');
    }

    public function query()
    {
        $search = mb_strtolower(trim((string) request('search')));
        $group = request('group');

        // Rows are configured triggers, not DB rows. ManualTrigger is an ad-hoc broadcast, not a
        // template-able trigger, so it never appears on the inheritance matrix.
        return collect(config('kompo-communications.triggers', []))
            ->reject(fn ($trigger) => $trigger === ManualTrigger::class)
            ->filter(fn ($trigger) => $search === '' || str_contains(mb_strtolower($trigger::getName()), $search))
            ->filter(fn ($trigger) => blank($group) || optional($this->groups->groupFor($trigger))->value() === $group)
            ->when(request('not_owned', false), fn ($c) => $c->reject(fn ($trigger) => $this->ownGroupFor($trigger) !== null))
            ->values();
    }

    public function headers()
    {
        return array_filter([
            _Th('communications.trigger'),
            $this->hasGroups ? _Th('communications.group') : null,
            _Th('communications.channels'),
            _Th('communications.source'),
            _Th('communications.last-sent'),
            _Th()->class('w-12'),
        ]);
    }

    public function render($trigger)
    {
        $resolution = $this->resolutionFor($trigger);

        return _TableRow(
            _Html($trigger::getName())->class('font-medium'),
            $this->hasGroups ? $this->groupCell($trigger) : null,
            $this->channelsCell($resolution),
            $this->sourceCell($resolution),
            _Html(communicationDateTime($this->lastSent($trigger), __('communications.never'))),
            $this->actionsCell($trigger, $resolution),
        );
    }

    /* ============================== Cells ============================== */

    protected function groupCell(string $trigger)
    {
        $group = $this->groups->groupFor($trigger);

        if (!$group) {
            return _Html('—')->class('text-gray-400');
        }

        return _Pill($group->label())->class('text-xs font-medium ' . $group->color());
    }

    protected function channelsCell(EffectiveTemplateResolution $resolution)
    {
        $templates = $resolution->group?->communicationTemplates;

        if (!$templates || $templates->isEmpty()) {
            return _Html('—')->class('text-gray-400');
        }

        return _Flex(
            $templates->map(function ($template) {
                $isDraft = (bool) $template->is_draft;

                return _Pill($template->type->label())
                    ->class('text-xs font-medium')
                    ->class($isDraft ? 'bg-red-100 text-red-700' : $template->type->color())
                    ->when($isDraft, fn ($pill) => $pill->balloon('communications.complete-all-info'));
            }),
        )->class('gap-1 flex-wrap');
    }

    protected function sourceCell(EffectiveTemplateResolution $resolution)
    {
        // OWN (customized) stands out gently; INHERITED is the quiet default state (it's on most rows,
        // so a loud pill there dominated the table); DISABLED soft-red; NONE lightest.
        return match ($resolution->source) {
            EffectiveTemplateSource::OWN => _Pill('communications.source-own')->class('text-xs font-medium bg-emerald-100 text-emerald-700'),
            EffectiveTemplateSource::INHERITED => _Pill($this->inheritedLabel($resolution))->class('text-xs font-medium bg-gray-100 text-gray-600'),
            EffectiveTemplateSource::DISABLED => _Pill('communications.source-disabled')->class('text-xs font-medium bg-red-100 text-red-700'),
            EffectiveTemplateSource::NONE => _Pill('communications.source-none')->class('text-xs font-medium bg-gray-100 text-gray-400'),
        };
    }

    protected function inheritedLabel(EffectiveTemplateResolution $resolution): string
    {
        if (!$resolution->ownerTeamId) {
            return __('communications.source-inherited-system');
        }

        $team = TeamModel::asSystemOperation()->find($resolution->ownerTeamId);

        return __('communications.source-inherited-from', ['team' => $team?->team_name ?? ('#' . $resolution->ownerTeamId)]);
    }

    protected function lastSent(string $trigger): ?string
    {
        return CommunicationSending::query()
            ->where('trigger', $trigger)
            ->where('team_id', $this->teamId)
            ->max('sent_at');
    }

    protected function actionsCell(string $trigger, EffectiveTemplateResolution $resolution)
    {
        $links = match ($resolution->source) {
            EffectiveTemplateSource::INHERITED => [
                _DropdownLink('communications.action-view')
                    ->selfGet('viewTemplate', ['trigger' => $trigger])->inModal(),
                _DropdownLink('communications.action-copy-edit')
                    ->selfGet('copyAndEdit', ['trigger' => $trigger])->inModal(),
            ],
            EffectiveTemplateSource::OWN => [
                _DropdownLink('communications.action-edit')
                    ->selfGet('editTemplate', ['trigger' => $trigger])->inModal(),
                _DropdownLink('communications.action-disable')
                    ->selfPost('disableTrigger', ['trigger' => $trigger])->refresh(),
                _DropdownLink('communications.action-reset')
                    ->selfPost('resetTrigger', ['trigger' => $trigger])->refresh(),
            ],
            // Own suppression -> the team can re-enable it. Ancestor suppression -> the team can't
            // clear someone else's row; it overrides by configuring its own enabled template
            // (per the inheritance rule "a descendant re-enables by configuring its own").
            EffectiveTemplateSource::DISABLED => $resolution->ownerTeamId === (int) $this->teamId
                ? [
                    _DropdownLink('communications.action-reenable')
                        ->selfPost('reEnableTrigger', ['trigger' => $trigger])->refresh(),
                ]
                : [
                    _DropdownLink('communications.action-configure')
                        ->selfGet('configureTrigger', ['trigger' => $trigger])->inModal(),
                ],
            EffectiveTemplateSource::NONE => [
                _DropdownLink('communications.action-configure')
                    ->selfGet('configureTrigger', ['trigger' => $trigger])->inModal(),
            ],
        };

        return _TripleDotsDropdown(
            ...collect($links)->map(fn ($link) => $link->checkAuthWrite($this->permissionKey, specificTeamId: $this->teamId)),
        )->class('justify-end');
    }

    /* ============================== Actions ============================== */

    /** INHERITED only: clone the inherited group into this team, then edit the clone (never the ancestor). */
    public function copyAndEdit($trigger)
    {
        $resolution = $this->resolutionFor($trigger);

        if (!$resolution->group) {
            abort(404, 'No inherited template to copy.');
        }

        $clone = $resolution->group->copyForTeam($this->teamId);

        $this->bustResolution($trigger);

        return new CommunicationTemplateForm($clone->id);
    }

    /** OWN only: edit the team's own override. */
    public function editTemplate($trigger)
    {
        return new CommunicationTemplateForm($this->ownGroupFor($trigger)?->id);
    }

    /** INHERITED only: read-only preview of the inherited template's channels. */
    public function viewTemplate($trigger)
    {
        $resolution = $this->resolutionFor($trigger);
        $channels = $resolution->group?->communicationTemplates ?? collect();

        return _Rows(
            _TitleMini($trigger::getName())->class('mb-1'),
            _Html($this->inheritedLabel($resolution))->class('text-sm text-gray-500 mb-4'),
            $channels->isEmpty()
                ? _Html('communications.source-none')->class('text-gray-400')
                : _Rows(
                    $channels->map(fn ($template, $i) => _Rows(
                        _Html($template->type->label())->class('font-semibold text-greenmain'),
                        _Html(ContentReplacer::highlightMentions((string) $template->subject))->class('text-sm font-medium'),
                        _Html(ContentReplacer::highlightMentions((string) $template->content))->class('text-sm text-gray-600'),
                    )->class($i < $channels->count() - 1 ? 'border-b pb-3 mb-3' : '')),
                ),
        )->class('p-6 max-w-2xl');
    }

    /** OWN only: suppress the trigger for this team and its subtree. */
    public function disableTrigger($trigger)
    {
        $group = $this->ownGroupFor($trigger);

        if ($group) {
            $group->disabled = true;
            $group->save();
        } else {
            // Bare suppression override — disabled groups never send, so they need no channels.
            $group = new CommunicationTemplateGroup();
            $group->trigger = $trigger;
            $group->title = $trigger::getName();
            $group->team_id = $this->teamId;
            $group->disabled = true;
            $group->save();
        }

        $this->bustResolution($trigger);
    }

    /** OWN only: delete the override; inheritance resumes up the hierarchy. */
    public function resetTrigger($trigger)
    {
        $this->ownGroupFor($trigger)?->delete();

        $this->bustResolution($trigger);
    }

    /** DISABLED only: clear the team's own suppression. */
    public function reEnableTrigger($trigger)
    {
        $group = $this->ownGroupFor($trigger);

        if (!$group) {
            return;
        }

        // A bare suppression row (no channels) returns to inheritance when cleared; a real override
        // that was turned off keeps its channels and just turns back on.
        if ($group->communicationTemplates()->count() === 0) {
            $group->delete();
        } else {
            $group->disabled = false;
            $group->save();
        }

        $this->bustResolution($trigger);
    }

    /** NONE only: seed a team-owned group (with default stub channels when available) and edit it. */
    public function configureTrigger($trigger)
    {
        $group = $this->ownGroupFor($trigger) ?? $this->seedOwnedGroup($trigger);

        $this->bustResolution($trigger);

        return new CommunicationTemplateForm($group->id);
    }

    /* ============================== Internals ============================== */

    public function resolutionFor(string $trigger): EffectiveTemplateResolution
    {
        return $this->resolver->resolve($trigger, (int) $this->teamId);
    }

    protected function ownGroupFor(string $trigger): ?CommunicationTemplateGroup
    {
        return CommunicationTemplateGroup::forTrigger($trigger)
            ->where('team_id', $this->teamId)
            ->first();
    }

    /**
     * Build a team-owned group seeded from the default stub blades. createForTrigger persists a
     * baseline (team_id NULL) row; we immediately re-stamp it to the viewing team. Safe here because
     * NONE means no baseline existed, so there is nothing to overwrite.
     */
    protected function seedOwnedGroup(string $trigger): CommunicationTemplateGroup
    {
        $group = CommunicationTemplateGroup::createForTrigger($trigger);
        $group->team_id = $this->teamId;
        $group->save();

        return $group->refresh();
    }

    /** Drop the per-request resolution cache so the immediate table refresh shows the new state. */
    protected function bustResolution(string $trigger): void
    {
        $this->resolver->forget($trigger, (int) $this->teamId);
    }
}
