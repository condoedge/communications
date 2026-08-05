<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Parsers;

use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Contracts\MentionParserInterface;
use Condoedge\Communications\Facades\Variables;

class HtmlMentionParser implements MentionParserInterface
{
    /** Translucent tint applied to a mention in the admin preview (no padding — just a soft field). */
    public const PREVIEW_TINT = 'background:rgba(0, 173, 29, 0.1);border-radius:3px;';

    public function getMentionFormat(string $varName): string
    {
        return '<span class="mention" data-mention="' . $varName . '">';
    }

    public function highlightMentions(string $html): string
    {
        return preg_replace_callback(
            $this->getAnyMentionPattern(),
            fn ($m) => '<span class="mention" data-mention="' . $m['id'] . '" style="' . self::PREVIEW_TINT . '">' . $m['label'] . '</span>',
            $html
        ) ?? $html;
    }

    public function matchesMention(string $subject, string $varName): bool
    {
        $label = $this->getMentionLabel($varName);

        if (preg_match($this->getHtmlMentionPattern($varName), $subject)) {
            return true;
        }

        if ($label !== null && preg_match($this->getBacktickMentionPattern($label), $subject)) {
            return true;
        }

        return false;
    }

    public function replaceMention(string $subject, string $varName, string $replaceWith): string
    {
        $subject = preg_replace($this->getHtmlMentionPattern($varName), $replaceWith, $subject) ?? $subject;

        $label = $this->getMentionLabel($varName);

        if ($label !== null) {
            $subject = preg_replace($this->getBacktickMentionPattern($label), $replaceWith, $subject) ?? $subject;
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

    protected function getMentionLabel(string $varId): ?string
    {
        $var = Variables::searchVarById($varId);

        if (!$var) {
            return null;
        }

        return trim((string) __($var[1]));
    }

    protected function getHtmlMentionPattern(string $varName): string
    {
        return '/<span\b[^>]*data-mention=("|\")' . preg_quote($varName, '/') . '\1[^>]*>.*?<\/span>/si';
    }

    /** Matches any mention span, capturing its id + label — the shape this parser emits. */
    protected function getAnyMentionPattern(): string
    {
        return '/<span\b[^>]*\bdata-mention=("|\')(?<id>[^"\']+)\1[^>]*>(?<label>.*?)<\/span>/si';
    }

    protected function getBacktickMentionPattern(string $label): string
    {
        return '/`{1,3}\s*' . preg_quote($label, '/') . '\s*`{1,3}/u';
    }

    public function getName(): string
    {
        return 'html';
    }
}