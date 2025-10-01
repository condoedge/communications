<?php

namespace Condoedge\Communications\Triggers;

use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;

class ManualTrigger implements CommunicableEvent
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

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        if (isset($context['communicable_type'])) {
            return config('kompo-communications.manual-trigger.valid-variables.' . $context['communicable_type'], null);
        }

        return null;
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

    static function manuallyForm($communicationGroupId = null)
    {
        return new GenericManualTriggerForm([
            'communication_id' => $communicationGroupId
        ]);
    }
}