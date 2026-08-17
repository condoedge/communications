<?php

namespace Condoedge\Communications\Triggers;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\EventsHandling\Contracts\DatabaseCommunicableEvent;
use Condoedge\Communications\Models\CommunicationCategory;

class ManualTrigger implements CommunicableEvent, DatabaseCommunicableEvent
{
    protected $communicationsIds;
    protected $communicablesIds;
    protected $communicableModel;

    public function __construct($communicationsIds, $communicableModel, $communicablesIds)
    {
        $this->communicationsIds = $communicationsIds;
        $this->communicablesIds = $communicablesIds;
        $this->communicableModel = $communicableModel;
    }

    public function getSpecificCommunicationsIds()
    {
        return $this->communicationsIds;
    }

    public static function getName(): string
    {
        return __('communications.manual-trigger');
    }

    /**
     * An admin-composed send to a chosen audience — the shape unsubscribe exists for, and the one
     * most likely to be a blast. The cost is real and deliberate: an admin cannot reach an
     * unsubscribed member this way, and must use a direct channel instead.
     */
    public static function communicationCategory(): CommunicationCategory
    {
        return CommunicationCategory::MARKETING;
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        if (isset($context['communicable_type'])) {
            return config('kompo-communications.manual-trigger.valid-variables.' . $context['communicable_type'], null);
        }

        return config('kompo-communications.manual-trigger.valid-variables.generic', null);
    }

    function getCommunicables(): array
    {
        if ($this->communicablesIds == 'all') {
            return $this->communicableModel::validForCommunication()->get()->all();
        }

        return $this->communicableModel::whereIn('id', $this->communicablesIds)->get()->all();
    }

    function getParams(): array
    {
        return [];
    }

    static function manuallyForm($communicationGroupId = null, $teamId = null)
    {
        return new GenericManualTriggerForm([
            'communication_id' => $communicationGroupId,
            'team_id' => $teamId,
        ]);
    }

    public static function getValidRoutes(): array
    {
        return collect(config('kompo-communications.manual-trigger.valid-routes', []))->map(function ($route) {
            return route($route);
        })->all();
    }
}