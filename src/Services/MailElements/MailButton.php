<?php
namespace Condoedge\Communications\Services\MailElements;

use Condoedge\Communications\Services\MailElements\MailElement;

class MailButton extends MailElement
{
    protected $url;

    public function __construct($label)
    {
        $this->internalElement = $label;

        // px units (Outlook ignores rem) + web-safe font stack and
        // -webkit-text-size-adjust to keep the label from being rescaled on mobile.
        $this->style = 'display: inline-block; padding: 12px 24px; background-color: #006241; color: #ffffff; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1; font-weight: 600; text-decoration: none; border-radius: 6px; -webkit-text-size-adjust: none; mso-padding-alt: 0;';
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