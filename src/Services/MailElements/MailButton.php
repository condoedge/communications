<?php
namespace Condoedge\Communications\Services\MailElements;

use Condoedge\Communications\Services\MailElements\MailElement;

class MailButton extends MailElement
{
    protected $url;

    public function __construct($label)
    {
        $this->internalElement = $label;

        $this->style = 'border-radius: 10px; padding: 0.15rem; background-color: #006241; color: white; text-decoration: none; display: inline-block;';
    }

    public function htmlStructure()
    {
        return '<a href="'.$this->url.'" style="'. $this->style.'" class="button" target="_blank" rel="noopener">'.__($this->internalElement).'</a>';
    }

    public function href($url)
    {
        $this->url = $url;

        return $this;
    }
}