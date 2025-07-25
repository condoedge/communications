<?php

namespace Condoedge\Communications\Services\EnhancedEditor;

use Condoedge\Communications\Facades\Variables;
use Kompo\TranslatableEditor;

class EnhancedEditor extends TranslatableEditor
{
    public $vueComponent = 'CKEditorWithVars';
    protected $uniqueId;

	public function initialize($label)
	{
        parent::initialize($label);

        $this->uniqueId(); 

		$this->class('vlTranslatableEditor relative comms-editor !mb-0');

        $this->setVariablesSection();
	}

    public function withoutTopBar()
    {
        return $this->toolbar([])->class('[&>.vlInputWrapper>.ck-editor>.ck-editor__top]:hidden');
    }

    public function baseInputHeight()
    {
        return $this->class('ck-content-h-10');
    }

    public function uniqueId()
    {
        $this->uniqueId = uniqid();

        return $this->config([
            'uniqueId' => $this->uniqueId,
        ]);
    }

    public function setVariablesSection($section = 'default')
    {
        Variables::setUniqueId($this->uniqueId);

        $variables = Variables::getVariables($section);

        return $this->config([
            'variables' => $variables,
        ]);
    }

    /**
     * Filter the variables to only the ones that are in the $variablesIds array
     * @param mixed $variablesIds The ids of the variables to filter. If null we're not filtering
     * @param mixed $section The section of the variables to filter. Default is 'default'
     * @return static
     */
    public function filterVarsToThisIds($variablesIds, $section = 'default')
    {
        if ($variablesIds === null) {
            return $this;
        }

        $vars = Variables::getRawVariables($section);

        // Filtering the variables to only the ones that are in the $variablesIds array
        $filtered = collect($vars)->map(function($group, $groupKey) use ($variablesIds) {
            return collect($group)->filter(function($var) use ($variablesIds, $groupKey) {
                return in_array($var[0], $variablesIds) || in_array($groupKey . '.*', $variablesIds);
            })->all();
        })->filter(fn($group) => count($group))->all();

        return $this->config([
            'variables' => Variables::automaticVarParsing($filtered),
        ]);
    }
}
