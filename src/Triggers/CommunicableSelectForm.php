<?php

namespace Condoedge\Communications\Triggers;

use Condoedge\Utils\Kompo\Common\Form;

class CommunicableSelectForm extends Form
{
    protected $communicableModel;

    public function created()
    {
        $this->communicableModel = $this->prop('communicable_model');
    }

    public function render()
    {
        return _MultiSelect('communications.select-communicables')->name(name: 'communicables_ids')
            ->searchOptions(3, 'searchCommunicables');
    }

    public function searchCommunicables($search)
    {
        $communicables = $this->communicableModel::search($search)->get();

        return array_merge(['all' => __('communications.everyone')], $communicables->mapWithKeys(fn($c) => [
            $c->id => $c->label()
        ])->toArray());
    }
}