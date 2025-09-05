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

    public function getName(): string
    {
        return 'brace';
    }
}