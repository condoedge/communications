<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager;

class ContextEnhancer
{
    protected static $enhancers = [];
    protected $context;
    protected $enhancedContext = [];

    public function __construct($context)
    {
        $this->context = $context;
    }

    // SETTERS 
    public static function setEnhancers(array $enhancers)
    {
        static::$enhancers = $enhancers;
    }


    // GETTERS
    public function getEnhancedContext()
    {
        if ($this->enhancedContext) {
            return $this->enhancedContext;
        }

        collect($this->context)->each(function ($value, $key) {
            if (array_key_exists($key, static::$enhancers)) {
                $enhancer = static::$enhancers[$key];

                $this->enhancedContext = array_merge($enhancer($value), $this->enhancedContext);
            }
        });

        $this->enhancedContext = array_merge($this->enhancedContext, $this->context);

        return $this->enhancedContext;
    }
}