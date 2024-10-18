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

		$this->class('vlTranslatableEditor relative comms-editor');

        $this->setVariablesSection();
	}

    public function withoutHeight()
    {
        return $this->config([
            'withoutHeight' => true,
        ]);
    }

    public function setVariablesSection($section = 'default')
    {
        $variables = Variables::getVariables($section);

        $this->config([
            'variables' => $variables,
        ]);
    }
}
