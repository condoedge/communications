<?php

namespace Condoedge\Communications\Facades;

/**
 * @mixin \Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\ContextEnhancer
 */
class ContextEnhancer extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'communcations-context-enhancer';
    }
}