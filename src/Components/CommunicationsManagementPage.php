<?php

namespace Condoedge\Communications\Components;

use Condoedge\Utils\Kompo\Common\Form;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/** Communications management hub for a team. Host apps subclass to swap the tabs wrapper. */
class CommunicationsManagementPage extends Form
{
    public $class = 'pb-8 container mx-auto px-4';

    protected $teamId;
    protected $permissionKey = 'Communication';

    public function created()
    {
        $this->teamId = $this->prop('team_id') ?: currentTeamId();

        if (!auth()->user()?->hasPermission($this->permissionKey, PermissionTypeEnum::WRITE, [$this->teamId])) {
            abort(403);
        }
    }

    public function render()
    {
        $teamId = $this->teamId;

        return _Rows(
            _TitleMain('communications.communications-management')->class('mb-6'),
            $this->tabsWrapper(
                _LazyTab(fn () => new CommunicationsOverview(['team_id' => $teamId]), 'metric')
                    ->id('overview')->label('communications.tab-overview'),
                _LazyTab(fn () => new CommunicationTemplatesList(['team_id' => $teamId]), 'table')
                    ->id('templates')->label('communications.tab-templates')
                    ->checkAuthWrite($this->permissionKey, specificTeamId: $this->teamId, returnNullInstead: true),
                _LazyTab(fn () => new CommunicationManualList(['team_id' => $teamId]), 'table')
                    ->id('manual')->label('communications.tab-manual')
                    ->checkAuthWrite($this->permissionKey, specificTeamId: $this->teamId, returnNullInstead: true),
                _LazyTab(fn () => new CommunicationSendLogList(['team_id' => $teamId]), 'table')
                    ->id('send-log')->label('communications.tab-send-log'),
                _LazyTab(fn () => new CommunicationByTriggerList(['team_id' => $teamId]), 'table')
                    ->id('by-trigger')->label('communications.tab-by-trigger'),
                _LazyTab(fn () => new CommunicationByTeamList(['team_id' => $teamId]), 'table')
                    ->id('by-team')->label('communications.tab-by-team'),
            ),
        );
    }

    protected function tabsWrapper(...$tabs)
    {
        return _LazyTabs(...$tabs);
    }
}
