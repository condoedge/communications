<?php

namespace Condoedge\Communications\Services\MailElements;

/**
 * Stacks mail elements vertically. The mail-side counterpart of _Rows — Kompo
 * components must never be returned to a replacer, they string-cast to JSON.
 */
class MailRows extends MailElement
{
    protected $elements;

    /** Off by default — elements carry their own spacing (a MailButton has a bottom margin). */
    protected $gap = null;

    public function __construct($elements = [])
    {
        $this->elements = collect($elements)->flatten()->filter()->values();
    }

    /** Vertical space between rows. */
    public function gap($gap)
    {
        $this->gap = $gap;

        return $this;
    }

    public function htmlStructure()
    {
        $last = $this->elements->count() - 1;

        $rows = $this->elements->map(function ($element, $i) use ($last) {
            $spacing = ($this->gap && $i !== $last) ? ' style="padding-bottom: ' . $this->gap . ';"' : '';

            return '<div' . $spacing . '>' . $this->renderElement($element) . '</div>';
        })->implode('');

        return '<div' . ($this->class ? ' class="' . $this->class . '"' : '')
            . ($this->style ? ' style="' . $this->style . '"' : '') . '>' . $rows . '</div>';
    }

    protected function renderElement($element)
    {
        return $element instanceof MailElement ? $element->getHtml() : (string) $element;
    }
}
