<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Parsers;

use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Contracts\MentionParserInterface;
use Condoedge\Communications\Facades\Variables;

class BraceMentionParser implements MentionParserInterface
{
    public function getMentionFormat(string $varName): string
    {
        return '{{' . $varName . '}}';
    }

    public function matchesMention(string $subject, string $varName): bool
    {
        return str_contains($subject, $this->getMentionFormat($varName));
    }

    public function replaceMention(string $subject, string $varName, string $replaceWith): string
    {
        $mention = $this->getMentionFormat($varName);
        return str_replace($mention, $replaceWith, $subject);
    }

    public function buildVar(string $varId): string
    {
        $var = Variables::searchVarById($varId);

        if (!$var) {
            return '';
        }

        return '{{' . $varId . '}}';
    }

    /** The editor emits mentions as HTML spans, so brace syntax needs no preview tint. */
    public function highlightMentions(string $html): string
    {
        return $html;
    }

    public function getName(): string
    {
        return 'brace';
    }
}