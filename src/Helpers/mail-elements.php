<?php

use Condoedge\Communications\Services\MailElements\CenteredElement;
use Condoedge\Communications\Services\MailElements\MailButton;
use Condoedge\Communications\Services\MailElements\MailGeneric;
use Condoedge\Communications\Services\MailElements\MailImage;
use Condoedge\Communications\Services\MailElements\MailRows;

function _MailButton($label)
{
    return new MailButton($label);
}

/** Accepts elements, raw html strings, arrays or collections — like _Rows. */
function _MailRows(...$elements)
{
    return new MailRows($elements);
}

function _MailImage($alt = '')
{
    return new MailImage($alt);
}

function _MailCenteredElement(...$elements)
{
    $html = collect($elements)->map(function ($element) {
        return $element->getHtml();
    })->implode('');

    return new CenteredElement($html);
}

function _MailParagraph($text)
{
	return new MailGeneric(function () use ($text) {
        return '<p style="font-size: 16px; line-height: 1.5; margin: 0; padding: 0; ' . $this->style . '">' . __($text) . '</p>';
    });
}
