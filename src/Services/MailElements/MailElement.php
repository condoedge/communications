<?php

namespace Condoedge\Communications\Services\MailElements;

abstract class MailElement
{
    protected $class = '';
    protected $style = '';
    protected $internalElement;

    protected $config = [];
    

    abstract public function htmlStructure();

    public function class($class)
    {
        $this->class .= $class;

        return $this;
    }

    public function style($style)
    {
        $this->style .= $style;

        return $this;
    }

    public function centerElement()
    {
        return $this->config([
            'wrappers' => [CenteredElement::class],
        ]);
    }

    public function getHtml()
    {
        if ($wrappers = $this->config('wrappers')) {
            return collect($wrappers)->reduce(function ($element, $wrapper) {
                return (new $wrapper($element))->getHtml();
            }, $this->htmlStructure());
        }

        return $this->htmlStructure();
    }

    public function config($config)
    {
        if (is_string($config)) {
           return $this->config[$config] ?? null;
        }

        if (!is_array($config)) {
            return $this;
        }

        $this->config = array_merge($this->config, $config);

        return $this;
    }

    public function setInternalElement($element)
    {
        $this->internalElement = $element;

        return $this;
    }
}