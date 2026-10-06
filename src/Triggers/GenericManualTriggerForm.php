<?php

namespace Condoedge\Communications\Triggers;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable;
use Kompo\Modal;

class GenericManualTriggerForm extends Modal
{
    protected $_Title = 'communications.send-manual-communication';

    protected $communicationId;
    protected $teamId;

    public $nestedFields = true;

    public function created()
    {
        $this->communicationId = $this->prop('communication_id');
        $this->teamId = $this->prop('team_id') ?: currentTeamId();
    }

    public function handle()
    {
        // The select's options are a display filter, not a control: both ids arrive raw on submit.
        $group = $this->authorizedGroup(request('communication_id'));

        $communicablesIds = request('communicables_ids');

        if (collect($communicablesIds)->some(function ($id) {
            return $id == 'all';
        })) {
            $communicablesIds = 'all';
        }

        event(new ManualTrigger([$group->id], $this->communicableType(), $communicablesIds));
    }

    public function body()
    {
        return _Rows(
            _Select('communications.select-communication')->name('communication_id')
                ->searchOptions(0, 'searchManualCommunications', 'retrieveCommunication')
                ->default($this->communicationId),

            _Select('communications.select-communicable-type')->name('communicable_model')
                ->selfGet('selectCommunicables')->inPanel('communicables-select')
                ->options($this->communicableTypes()),

            _Panel()->id('communicables-select'),

            _SubmitButton('communications.send')->alert('communications.sent')->closeModal(),
        );
    }

    public function footer()
    {
        return null;
    }

    public function selectCommunicables()
    {
        return _MultiSelect('communications.select-communicables')->name(name: 'communicables_ids')
            ->searchOptions(3, 'searchCommunicables')
            ->ajaxPayload(['communicable_model' => request('communicable_model')]);
    }

    public function searchCommunicables($search)
    {
        $communicableModel = $this->communicableType();
        $communicables = $communicableModel::search($search)->get();

        return ['all' => __('communications.everyone')] + $communicables->mapWithKeys(fn($c) => [
            $c->id => $c->label()
        ])->toArray();
    }

    public function retrieveCommunication($id)
    {
        $communication = CommunicationTemplateGroup::manualForTeam($this->teamId)->find($id);

        if (!$communication) {
            return [];
        }

        return [
            $communication->id => $communication->title
        ];
    }

    public function searchManualCommunications($search)
    {
        $communications = CommunicationTemplateGroup::manualForTeam($this->teamId)
            ->where('title', 'like', "%$search%")->get();

        return $communications->pluck('title', 'id');
    }

    /** The group must be one the current team owns — the model carries no team global scope. */
    protected function authorizedGroup($id): CommunicationTemplateGroup
    {
        return CommunicationTemplateGroup::manualForTeam($this->teamId)->findOrFail($id);
    }

    /** A raw class string from the request is not a recipient model; only the offered options are. */
    protected function communicableType(): string
    {
        $model = request('communicable_model');

        abort_unless($this->communicableTypes()->has($model), 403);

        return $model;
    }

    protected function communicableTypes()
    {
        if (!config('kompo-communications.communicable-types')) {
            return $this->communicablesFromAllProject();
        }

        return collect(config('kompo-communications.communicable-types'))->mapWithKeys(fn($class, $label) => [$class => __($label)]);
    }

    protected function communicablesFromAllProject()
    {
        $directory = app_path('Models');
        self::loadDirectoryFiles($directory);

        return collect(get_declared_classes())
            ->filter(function ($class) {
                $reflectionClass = new \ReflectionClass($class);

                return !$reflectionClass->isAbstract() && in_array(Communicable::class, class_implements($class));
            })
            ->unique(fn($class) => (new $class)->getTable())
            ->mapWithKeys(fn($class) => [$class => __('communicable.' . (new $class)->getTable())]);
    }

    protected function loadDirectoryFiles($directory)
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $phpFiles = new \RegexIterator($files, '/\.php$/');

        foreach ($phpFiles as $file) {
            try {
                require_once $file->getRealPath();
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    public function rules()
    {
        return [
            'communication_id' => 'required',
            'communicable_model' => 'required',
            'communicables_ids' => 'required',
        ];
    }
}
