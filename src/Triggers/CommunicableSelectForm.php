<?php

namespace Condoedge\Communications\Triggers;

use Kompo\Auth\Common\Form;

class CommunicableSelectForm extends Form
{
    protected $communicableModel;

    public function created()
    {
        $this->communicableModel = $this->prop('communicable_model');
    }

    public function render()
    {
        return _MultiSelect('translate.select-communicables')->name(name: 'communicables_ids')
            ->searchOptions(3, 'searchCommunicables');
    }

    public function searchCommunicables($search)
    {
        $communicables = $this->communicableModel::search($search)->get();

        return array_merge(['all' => __('translate.everyone')], $communicables->mapWithKeys(fn($c) => [
            $c->id => $c->label()
        ])->toArray());
    }
}