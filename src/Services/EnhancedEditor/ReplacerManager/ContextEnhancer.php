<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager;

class ContextEnhancer
{
    protected $enhancers = [];
    protected $context;
    protected $enhancedContext = [];

    // SETTERS 
    public function setEnhancers(array $enhancers)
    {
        $this->enhancers = $enhancers;

        return $this;
    }

    public function setContext($context)
    {
        $this->context = $context;

        return $this;
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