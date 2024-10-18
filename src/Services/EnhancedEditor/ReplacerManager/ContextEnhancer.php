<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager;

class ContextEnhancer
{
    protected $enhancers = [];
    protected $context = [];
    protected $enhancedContext = [];

    // SETTERS 
    /**
     * Set enhancers for the context.
     * 
     * The enhancers will be worth for complete the context using the values of the context. The idea is get from the
     * current context the values and execute the enhancer to get the complete context.
     * 
     * Keep in mind that you could use the method `enhanceContext` in the context values (like a model) to enhance the context. This is only other way to set enhancers.
     * But if the value is not an object, you should use this enhancers handler, because you are not able to set the method
     *
     * @param array<string, callable> $enhancers An array of enhancers to set for the context. Each enhancer is a callable to get extra info from an element of the context.
     * @return static
     *
     * @example
     * $enhancers = [
     *     'event' => function ($event) {
     *          return ['team' => $event->team]
     *      },
     * ];
     */
    public function setEnhancers(array $enhancers)
    {
        $this->enhancers = $enhancers;

        return $this;
    }

    /**
     * Set the context to enhance.
     * 
     * The context is an associative array of values that will be enhanced using the enhancers.
     *
     * @param array<string, mixed> $context An associative array of context to enhance.
     * @return static
     */
    public function setContext(array $context)
    {
        $this->context = $context;

        return $this;
    }

    // GETTERS
    /**
     * Get the enhanced context.
     * 
     * This method enhances the context by applying the enhancers to the current context values. If an enhancer is defined
     * for a context key, it will be executed and its result will be merged into the enhanced context. Additionally, if a
     * context value has a method `enhanceContext`, it will be called to further enhance the context.
     *
     * @return array<string, mixed> The enhanced context.
     */
    public function getEnhancedContext()
    {
        if ($this->enhancedContext) {
            return $this->enhancedContext;
        }

        collect($this->context)->each(function ($value, $key) {
            if (array_key_exists($key, $this->enhancers)) {
                $enhancer = $this->enhancers[$key];

                $this->enhancedContext = array_merge($enhancer($value), $this->enhancedContext);
            }

            if (method_exists($value, 'enhanceContext')) {
                $this->enhancedContext = array_merge($value->enhanceContext($value), $this->enhancedContext);
            }
        });

        $this->enhancedContext = array_merge($this->enhancedContext, $this->context);

        return $this->enhancedContext;
    }
}
