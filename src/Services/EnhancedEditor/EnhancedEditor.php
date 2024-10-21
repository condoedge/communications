<?php

namespace Condoedge\Communications\Services\EnhancedEditor;

use Condoedge\Communications\Facades\Variables;
use Kompo\TranslatableEditor;

class EnhancedEditor extends TranslatableEditor
{
    public $vueComponent = 'CKEditorWithVars';

	public function initialize($label)
	{
        parent::initialize($label);

		$this->class('vlTranslatableEditor relative comms-editor !mb-0');

        $this->setVariablesSection();
	}

    public function withoutTopBar()
    {
        return $this->class('[&>.vlInputWrapper>.ck-editor>.ck-editor__top]:hidden');
    }

    public function setVariablesSection($section = 'default')
    {
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
        $filtered = collect($vars)->map(function($group) use ($variablesIds) {
            return collect($group)->filter(function($var) use ($variablesIds) {
                return in_array($var[0], $variablesIds);
            })->all();
        })->filter(fn($group) => count($group))->all();

        return $this->config([
            'variables' => Variables::automaticVarParsing($filtered),
        ]);
    }
}
