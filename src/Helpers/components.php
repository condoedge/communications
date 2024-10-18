<?php

if (!function_exists('_EnhancedEditor')) {
    function _EnhancedEditor()
    {
        return \Condoedge\Communications\Services\EnhancedEditor\EnhancedEditor::form(...func_get_args());
    }
}
