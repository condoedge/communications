<?php

namespace Condoedge\Communications\Facades;

/**
 * @mixin \Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\MessageContentReplacer
 */
class ContentReplacer extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'communcations-content-replacer';
    }
}