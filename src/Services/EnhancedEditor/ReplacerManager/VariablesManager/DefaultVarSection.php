<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\VariablesManager;

class DefaultVarSection
{
    protected string $title;
    protected string $classes;
    /**
     * @var DefaultVarLink[]
     */
    protected $links = [];

    protected $uniqueId;
    
    public function __construct(string $title, $links, string $classes = 'bg-white border border-level1 py-2 px-3 rounded-xl text-level1 min-w-[200px] full-width-submenu')
    {
        $this->title = $title;
        $this->links = $links;

        $this->classes = $classes;
    }

    /**
     * Convert the section to a Dropdown element to be able to be used in the editor
     * @return mixed
     */
    public function getElementsParsed($uniqueId)
    {
        $transKey = 'comm-section.'.$this->title;

        return _Dropdown(trans()->has($transKey) ? trans($transKey) : $this->title)->submenu(collect($this->links)->map(function($link) use($uniqueId){
            return $link->getElementParsed($uniqueId);
        }))->alignUpRight()->class('varsDropdown')->class($this->classes);
    }

    /**
     * Convert an array for VariablesManager structure to a DefaultVarSection
     * 
     * @see \Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\VariablesManager\VariablesManager
     * 
     * @param string $title
     * @param array $section
     * @return static
     */
    public static function fromArray(string $title, array $section): self
    {
        return new self($title, links: collect(value: $section)->map(function($var){
            self::validateVariable($var);

            [$id, $link, $classes, $automaticHandling] = $var;

            return new DefaultVarLink($id, $link, $classes);
        })->toArray());
    }

    /**
     * Validate if the variable has correct structure
     * @param array $variable
     * @throws \Exception
     * @return void
     */
    public static function validateVariable(array $variable)
    {
        if(count($variable) != 4){
            throw new \Exception('The section must have 4 parameters: id, link, classes, automaticHandling');
        }

        if(!is_string($variable[0]) || !is_string($variable[1]) || !is_string($variable[2]) || !is_bool($variable[3])){
            throw new \Exception('The section parameters must be: string, string, string, bool');
        }
    }
}