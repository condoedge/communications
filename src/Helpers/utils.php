<?php

if (!function_exists('getVarHtml')) {
    function getVarHtml($varName)
    {
        return \Condoedge\Communications\Facades\ContentReplacer::getVarHtml($varName);
    }
}