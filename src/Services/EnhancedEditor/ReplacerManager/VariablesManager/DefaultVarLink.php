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

    /**
     * Return a var converted to a link element
     * 
     * @return \Kompo\Link
     */
    public function getElementParsed($uniqueId)
    {
        return _Link($this->name)->attr(['data-type' => $this->id])
            ->class($this->classes . ' hover:bg-blue-50 text-black bg-white rounded-lg px-3 py-2 varsLink')
            ->emitRoot('insertVariable', ['type' => $this->id, 'label' => __($this->name), 'uniqueid' => $uniqueId]);
    }
}