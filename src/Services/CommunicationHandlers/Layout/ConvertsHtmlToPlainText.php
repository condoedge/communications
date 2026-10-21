<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Layout;

/**
 * Shared HTML-to-text conversion for the layouts that deliver a plain-text body
 * (the email text part and the SMS content).
 */
trait ConvertsHtmlToPlainText
{
    /**
     * Best-effort HTML-to-text conversion for the plain-text part. A text
     * alternative materially improves spam scoring and accessibility.
     */
    protected function toPlainText(string $html): string
    {
        $text = preg_replace('/<\s*(br|\/p|\/div|\/h[1-6]|\/li|\/tr)\s*\/?>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
