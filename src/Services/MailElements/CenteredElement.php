<?php

namespace Condoedge\Communications\Services\MailElements;

class CenteredElement extends MailElement
{
    protected $internalStyle;

    public function __construct($element)
    {
        $this->internalElement = $element;
    }

    public function htmlStructure()
    {
        return '<table class="action table-without-borders" style="' . $this->style . '" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
        <td align="center">
        <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
        <td align="center">
        <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="' . $this->internalStyle . '">
        <tr>
        <td align="center">' . $this->internalElement . '</td>
        </tr>
        </table>
        </td>
        </tr>
        </table>
        </td>
        </tr>
        </table>';
    }
}
