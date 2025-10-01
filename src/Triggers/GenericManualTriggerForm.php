<?php

namespace Condoedge\Communications\Triggers;

use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable;
use Kompo\Modal;

class GenericManualTriggerForm extends Modal
{
    protected $_Title = 'communications.send-manual-communication';

    protected $communicationId;

    public function created()
    {
        $this->communicationId = $this->prop('communication_id');
    }

    public function handle()
    {
        $communicablesIds = request('communicables_ids');

        if (collect($communicablesIds)->some(function ($id) {
            return $id == 'all';
        })) {
            $communicablesIds = 'all';
        }

        event(new ManualTrigger([request('communication_id')], request('communicable_model'), $communicablesIds));
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

    public function selectCommunicables()
    {
        if(!request('communicable_model')) return null;

        return new CommunicableSelectForm(null, [
            'communicable_model' => request('communicable_model')
        ]);
    }

    public function retrieveCommunication($id)
    {
        $communication = CommunicationTemplateGroup::find($id);

        return [
            $communication->id => $communication->title
        ];
    }

    public function searchManualCommunications($search)
    {
        $communications = CommunicationTemplateGroup::where('title', 'like', "%$search%")->get();

        return $communications->pluck('title', 'id');
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
