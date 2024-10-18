<?php

namespace Condoedge\Communications\Facades;

/**
 * @mixin \Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\VariablesManager\VariablesManager
 */
class Variables extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'communication-variables-manager';
    }
}