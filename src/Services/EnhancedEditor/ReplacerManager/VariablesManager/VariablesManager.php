<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\VariablesManager;

class VariablesManager
{
    protected $variables = [];
    protected $rawVariables = [];
    protected $sectionParser = DefaultVarSection::class;

    // SETTERS

    /**
     * Set variables for a specific group of vars.
     *
     * This method allows you to set variables for a given group. The variables are passed as an associative array
     * where the key is the section name and the value is an array of variables. Each variable is an array containing
     * the variable ID, name, CSS classes, and a boolean flag for automatic handling.
     * 
     * With the automatic handling flag set to true, the variable will be automatically replaced if you use this structure in the id {model.variable}
     * You could pass a method but just if it doesn't have any parameters.
     * If you're not using the automatic handling you should be MessageContentReplacer::setHandlers
     * 
     * @see \Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\MessageContentReplacer::setHandlers
     *
     * @param array<string, array{string, string, string, bool}> $varsEls An array of variables to set for the group. 
     * The structure should be ['section' => ['id', 'name', 'classes', automatic_handling (bool)]].
     * @param string $group The group to set the variables for. You will be able to get the variables by this group when you're using getVariables.
     * @return void
     *
     * @example
     * $varsEls = [
     *     'events' => [['event.name_ev', 'Event name', 'bg-level1', true]],
     *     'teams' => [
     *          ['team_name', 'Footer Variable', 'bg-level2', false],
     *          ['team_link', 'Team link', 'bg-level2', false], 
     *      ],
     * ];
     */
    public function setVariables(array $varsEls, $group = 'default')
    {
        $this->rawVariables[$group] = $varsEls;

        $this->variables[$group] = $this->automaticVarParsing($varsEls);
    }

    /**
     * Set the section parser. Is used to convert the array of vars to a final element. The default one use dropdown and links.
     * @param DefaultVarSection $sectionParser The section parser to use. It should be an instance of DefaultVarSection.
     * @throws \Exception
     * @return void
     */
    public function setSectionParser($sectionParser)
    {
        if (!($sectionParser instanceof DefaultVarSection)) {
            throw new \Exception('The section parser must be an instance of DefaultVarSection.');
        }

        $this->sectionParser = $sectionParser;
    }

    // GETTERS
    /**
     * Get the groups of variables.
     * @return array
     */
    public function getGroups()
    {
        return array_keys($this->variables);
    }

    /**
     * Get the raw variables.
     * @return \Illuminate\Support\Collection<array{string, string, string, bool}>
     */
    public function getRawVariables()
    {
        return collect($this->rawVariables)->flatten(2);
    }

    /**
     * Get the variables for a specific section. The main use now is in the class EnhancedEditor
     * 
     * @see Condoedge\Communications\Services\EnhancedEditor\EnhancedEditor::setVariablesSection
     *
     * @param string $section The section to get the variables for. Default is 'default'.
     * @return mixed
     */
    public function getVariables($section = 'default')
    {
        return $this->variables[$section];
    }

    /**
     * Parsing the variables to the final element to show in the editor.
     * @param array $vars
     * @return array
     */
    protected function automaticVarParsing(array $vars) 
    {
        return collect($vars)->map(callback: function($vars, $title){
            return $this->sectionParser::fromArray($title, $vars)->getElementsParsed();
        })->toArray();
    }
}