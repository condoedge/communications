<?php

if (!function_exists('getVarHtml')) {
    function getVarHtml($varName)
    {
        return \Condoedge\Communications\Facades\ContentReplacer::getVarBuilt($varName, 'html');
    }
}
if (!function_exists('getVarBuilt')) {
    function getVarBuilt($varId, ?string $parserName = null)
    {
        return \Condoedge\Communications\Facades\ContentReplacer::getVarBuilt($varId, $parserName);
    }
}