<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\VariablesManager;

class DefaultVarLink
{
    protected string $id;
    protected string $name;
    protected string $classes;

    public function __construct(string $id, string $name, string $classes = '')
    {
        $this->id = $id;
        $this->name = $name;
        $this->classes = $classes;
    }

    public function getElementParsed()
    {
        return $this->link($this->name, $this->id, $this->classes);
    }

    protected function link($label, $type, $class = null)
	{
		return _Link($label)->attr(['data-type' => $type])
            ->class($class . ' hover:bg-blue-50 text-black bg-white rounded-lg px-3 py-2 varsLink')
            ->emitRoot('insertVariable', ['type' => $type, 'label' => __($label)]);
	}
}