<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Utils\Facades\GlobalConfig;

/**
 * The channels of a template group inside a form: one button per channel and, under them, the form
 * of the channel picked. Switching saves the channel being left, and so does the form on submit.
 *
 * The form using it says which group it edits (channelsGroup(), which may not be saved yet) and
 * renders channelsSection() among its own fields.
 */
trait EditsCommunicationChannels
{
    abstract protected function channelsGroup(): CommunicationTemplateGroup;

    /** What the channel forms are given besides the trigger. */
    protected function channelsContext(): array
    {
        return [];
    }

    protected function channelsSection()
    {
        $group = $this->channelsGroup();
        $channel = $this->openedChannel($group->trigger);

        return _Panel(
            $this->getFormsTypes(
                $this->channelForm($channel, $group->findCommunicationTemplate($channel->value), $group->trigger),
            )
        )->id('communication-type-form-container');
    }

    /** The channel whose form the section shows when it is built. */
    protected function openedChannel($trigger): CommunicationType
    {
        return $this->defaultChannel($trigger);
    }

    /** The form of one channel. A form with its own fields for a channel overrides this and adds previousChannelField(). */
    protected function channelForm(CommunicationType $type, ?CommunicationTemplate $template, $trigger)
    {
        return $type->handler($template)->getForm($trigger, $this->channelsContext());
    }

    /** Tells the next request which channel's fields it carries. */
    protected function previousChannelField(CommunicationType $type)
    {
        return _Hidden()->name('previous_communication_type', false)->value($type);
    }

    public function saveAndGetNewForm()
    {
        $group = $this->channelsGroup();

        $communicationType = request('communication_type');
        $trigger = request('trigger') ?? $group->trigger;
        $previousCommunicationType = request('previous_communication_type');

        if ($previousCommunicationType) {
            $this->savePreviousCommunication($previousCommunicationType);
        } else if (!$communicationType) { // If there is no previous communication type, we are probably coming from the trigger select, so we set a default
            $communicationType = $this->defaultChannel($trigger)->value;
        }

        if (!$communicationType) {
            return _Html('communications.select-communication-type-to-edit')->class('text-center');
        }

        $communicationType = CommunicationType::from($communicationType);

        // Read again: saving the channel being left may have created the group.
        return $this->channelForm(
            $communicationType,
            $this->channelsGroup()->findCommunicationTemplate($communicationType->value),
            $trigger,
        );
    }

    public function saveAndGetNewFormTypes()
    {
        $handler = $this->saveAndGetNewForm();

        return $this->getFormsTypes($handler);
    }

    public function getFormsTypes($handler = null)
    {
        $trigger = $this->channelsGroup()->trigger ?? request('trigger');

        return _Rows(
            _ButtonGroup()->options(
                    $this->channelsFor($trigger)
                        ->mapWithKeys(fn($t) => [$t->value => $t->label()])
                        ->all()
                )
                    ->optionClass('p-2 text-center')
                    ->name('communication_type', false)
                    ->default($this->openedChannel($trigger)->value)
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
            ->filter(fn($t) => static::offeredInEditor($t))
            ->filter(fn($t) => !$trigger || !method_exists($trigger, 'acceptsChannel') || $trigger::acceptsChannel($t))
            ->values();
    }

    /**
     * Whether the editor offers this channel to whoever is looking. A channel that cannot send in
     * this app is never offered. One being rolled out — listed in
     * `kompo-communications.channels.restricted-editor` — stays with super admins until the host
     * turns on `communications_channels.{channel}.public`.
     *
     * This hides the channel from the editor only: a template that already exists on a restricted
     * channel is still dispatched to every recipient.
     */
    public static function offeredInEditor(CommunicationType $type): bool
    {
        if (!$type->sendable()) {
            return false;
        }

        $restricted = in_array($type->configKey(), config('kompo-communications.channels.restricted-editor', []), true);

        return (bool) GlobalConfig::get("communications_channels.{$type->configKey()}.public", !$restricted)
            || (bool) isSuperAdmin();
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

        $group = $this->channelsGroup();

        $oldCommunication = $group->findCommunicationTemplate($communicationType);

        $previousCommunicationType = CommunicationType::from($communicationType);
        $previousCommunicationType->handler($oldCommunication)->save($group->id, request()->all());
    }
}
