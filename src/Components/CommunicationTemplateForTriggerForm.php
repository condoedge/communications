<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationTemplateGroup;

/**
 * CommunicationTemplateForm for a caller that already knows what it is composing: the trigger, the
 * owning team and a default name arrive as props, so the editor opens straight on its channels
 * screen and no group has to exist beforehand.
 *
 *     new CommunicationTemplateForTriggerForm(null, [
 *         'trigger' => ManualTrigger::class, 'team_id' => $teamId, 'title' => __('…new-manual-communication'),
 *     ])
 *
 * The group is created by the editor itself, the first time there is something to attach to it:
 * on Save, or on the first channel switch (the tab being left has to be saved somewhere). Opening
 * the editor and walking away writes nothing. Trigger and team come from the props (the komponent's
 * encrypted store), never from the request — there is no trigger field to post.
 */
class CommunicationTemplateForTriggerForm extends CommunicationTemplateForm
{
    /** Carries the group a channel switch created back to this editor, whose own key is still empty. */
    protected const GROUP_FIELD = 'template_group_id';

    protected $trigger;
    protected $teamId;

    public function created()
    {
        parent::created();

        $this->trigger = $this->prop('trigger');
        $this->teamId = $this->prop('team_id') ?: currentTeamId();

        if ($this->model->exists) {
            return;
        }

        // The id arrives raw, so it goes through the same scope the lists use: this team, this trigger.
        if ($groupId = request(static::GROUP_FIELD)) {
            $this->model(CommunicationTemplateGroup::manualForTeam($this->teamId, $this->trigger)->findOrFail($groupId));

            return;
        }

        // In memory only: everything the parent reads off the model works, nothing is written yet.
        $this->model->trigger = $this->trigger;
        $this->model->team_id = $this->teamId;
        $this->model->title = $this->prop('title') ?: $this->trigger::getName();
        // The team's own reusable communication, not a one-off direct_usage temp.
        $this->model->direct_usage = false;
    }

    protected function showsChannels(): bool
    {
        return true;
    }

    protected function mainFields()
    {
        if ($this->directUsage) {
            return parent::mainFields();
        }

        return _Rows(
            _Input('Name')->name('title'),
            !$this->model->exists ? null : _Hidden()->name(static::GROUP_FIELD, false)->value($this->model->id),
        );
    }

    /** A channel switch saves the tab being left, and a template needs a group to belong to. */
    protected function savePreviousCommunication($communicationType)
    {
        if ($communicationType && !$this->model->exists) {
            $this->model->title = request('title') ?: $this->model->title;
            $this->model->save();
        }

        return parent::savePreviousCommunication($communicationType);
    }

    public function saveAndGetNewForm()
    {
        $form = parent::saveAndGetNewForm();

        // Hand the new group's id to the browser, or the next request would create a second one.
        return !$this->model->exists ? $form : _Rows(
            _Hidden()->name(static::GROUP_FIELD, false)->value($this->model->id),
            $form,
        );
    }

    public function rules()
    {
        return [
            'title' => 'required',
        ];
    }
}
