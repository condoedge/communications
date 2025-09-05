<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Parsers;

use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Contracts\MentionParserInterface;
use Condoedge\Communications\Facades\Variables;

class HtmlMentionParser implements MentionParserInterface
{
    public function getMentionFormat(string $varName): string
    {
        return '<span class="mention" data-mention="' . $varName . '">';
    }

    public function replaceMention(string $subject, string $varName, string $replaceWith): string
    {
        $start = strpos($subject, $this->getMentionFormat($varName));

        while ($start > -1) {
            $closeTag = '</span>';
            $end = strpos($subject, $closeTag, $start + strlen($this->getMentionFormat($varName))) + strlen($closeTag);

            $subject = substr_replace($subject, $replaceWith, $start, $end - $start);

            $start = strpos($subject, $this->getMentionFormat($varName));
        }

        return $subject;
    }

    public function buildVar(string $varId): string
    {
        $var = Variables::searchVarById($varId);

        if (!$var) {
            return '';
        }

        return '<span class="mention" data-mention="' . $varId . '">' . __($var[1]) . '</span>';
    }

    public function getName(): string
    {
        return 'html';
    }
}