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
    
    public function __construct(string $title, $links, string $classes = 'bg-level1 py-2 px-3 rounded-xl text-white min-w-[200px] full-width-submenu')
    {
        $this->title = $title;
        $this->links = $links;

        $this->classes = $classes;
    }

    public function getElementsParsed()
    {
        return _Dropdown($this->title)->submenu(collect($this->links)->map(function($link){
            return $link->getElementParsed();
        }))->alignUpRight()->class('varsDropdown')->class($this->classes);
    }

    public static function fromArray(string $title, array $section): self
    {
        return new self($title, links: collect(value: $section)->map(function($var){
            self::validateVariable($var);

            [$id, $link, $classes, $automaticHandling] = $var;

            return new DefaultVarLink($id, $link, $classes);
        })->toArray());
    }

    public static function validateVariable(array $section)
    {
        collect($section)->each(callback: function($params){
            if(count($params) != 4){
                throw new \Exception('The section must have 4 parameters: id, link, classes, automaticHandling');
            }

            if(!is_string($params[0]) || !is_string($params[1]) || !is_string($params[2]) || !is_bool($params[3])){
                throw new \Exception('The section parameters must be: string, string, string, bool');
            }
        });
    }
}