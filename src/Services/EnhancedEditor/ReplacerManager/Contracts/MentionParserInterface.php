<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Contracts;

interface MentionParserInterface
{
    /**
     * Get the mention format for a variable
     * 
     * @param string $varName Variable name/id
     * @return string The formatted mention
     */
    public function getMentionFormat(string $varName): string;

    /**
     * Replace mentions in text with values
     * 
     * @param string $subject Text to process
     * @param string $varName Variable name to replace
     * @param string $replaceWith Value to replace with
     * @return string Processed text
     */
    public function replaceMention(string $subject, string $varName, string $replaceWith): string;

    /**
     * Build variable display format
     * 
     * @param string $varId Variable ID
     * @return string Built variable format
     */
    public function buildVar(string $varId): string;

    /**
     * Get parser identifier
     * 
     * @return string Parser name/type
     */
    public function getName(): string;
}