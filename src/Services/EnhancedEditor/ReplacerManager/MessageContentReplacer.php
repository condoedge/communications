<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager;

use Condoedge\Communications\Facades\Variables;
use Condoedge\Communications\Models\CommunicationType;
use ReflectionFunction;

class MessageContentReplacer
{
	protected $context;	
	protected static $handlers;

	protected $text;
	protected $parsedText;

	public function setText($text)
	{
		$this->text = $text;

		return $this;
	}

	public function getParsedText()
	{
		return $this->parsedText;
	}

	public function injectContext($context)
	{
		$this->context = $context;

		return $this;
	}

	/**
	 * 
	 * @param CommunicationType $type
	 * @return mixed
	 */
	public function replace(?CommunicationType $type = null)
    {
        $parsedText = $this->text;
		
        collect(Variables::getRawVariables())->each(function ($vars) use ($type, &$parsedText) {
            $this->processVariable($vars, $type, $parsedText);
        });

        return $parsedText;
    }

    protected function processVariable($vars, $type, &$parsedText)
    {
        [$id, $name, $classes, $automaticHandling] = $vars;

        $exists = strpos($this->text, static::getMentionHtml($id));

        if ($exists !== false) {
            $handler = static::$handlers[$id] ?? null;

            if ($handler) {
                $args = $this->getHandlerArguments($handler, $type);
                $parsedText = static::replaceMention($this->text, $id, $handler(...$args));
            }

            if ($automaticHandling && !$handler) {
                $parsedText = $this->handleAutomaticReplacement($id, $parsedText);
            }
        }
    }

    protected function getHandlerArguments($handler, $type)
    {
        $reflection = new ReflectionFunction($handler);
        $parameters = $reflection->getParameters();
        $args = [];

        foreach ($parameters as $parameter) {
            $parameterName = $parameter->getName();

            if ($parameterName == 'type') {
                $args[] = $type;
            } else if ($parameterName == 'context') {
                $args[] = $this->context;
            } else if (array_key_exists($parameterName, $this->context)) {
                $args[] = $this->context[$parameterName];
            } else {
                $args[] = null;
            }
        }

        return $args;
    }

    protected function handleAutomaticReplacement($id, $parsedText)
    {
        $parts = explode('.', $id);
        $modelName = $parts[0];
        $attribute = $parts[1] ?? null;

        $replaceWith = $attribute ? ($this->context[$modelName] ?? null)?->$attribute : ($this->context[$modelName] ?? null);

        if (is_callable($replaceWith)) {
            $replaceWith = $replaceWith();
        }

        return static::replaceMention($this->text, $id, $replaceWith);
    }

	/**
	 * 
	 * @param array<string, callable> $handlers
	 * @return void
	 */
	public static function setHandlers(array $handlers)
	{
		static::$handlers = $handlers;
	}


	// MENTION PARSERS
	static function getMentionHtml($type)
	{
		return '<span class="mention" data-mention="' . $type . '">';
	}

	static function replaceMention($subject, $type, $replaceWith)
	{
		$start = strpos($subject, static::getMentionHtml($type));

		while ($start > -1) {

			$closeTag = '</span>';
			$end = strpos($subject, $closeTag, $start + strlen(static::getMentionHtml($type))) + strlen($closeTag);

			$subject = substr_replace($subject, $replaceWith, $start, $end - $start);

			$start = strpos($subject, static::getMentionHtml($type));
		}

		return $subject;
	}
}
