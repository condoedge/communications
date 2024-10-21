<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager;

use Condoedge\Communications\Facades\Variables;
use Condoedge\Communications\Models\CommunicationType;
use ReflectionFunction;

/**
 * Class MessageContentReplacer
 *
 * This class is responsible for replacing content in texts.
 */
class MessageContentReplacer
{
	protected $context = [];	
	protected $handlers = [];

	protected string $text;

    // SETTERS

    /**
     * Set the value of the text to be parsed
     * @param string $text
     * @return static
     */
	public function setText(string $text)
	{
		$this->text = $text;

		return $this;
	}

    /**
     * An asociative array with the context to be injected in the text
     * @param array<string,mixed> $context
     * @return static
     */
    public function injectContext($context)
	{
		$this->context = array_merge($this->context, $context);

		return $this;
	}

    /**
     * Set the handlers to be used to replace the vars inserted in the text
     * 
     * The concept is to have a handler for each var that will be replaced in the text. The handler will be called with the context and the type of the communication
     * The arguments of the handler will be resolved using reflection to get the parameters names. So you should use the name of the context keys to get the values, or use the type to get the communication type
	 * The handler is not needed if you're using the automatic handling in the variables (fourth parameter of the variable)
     * 
	 * @param array<string, callable> $handlers An associative array with the handlers to be used. The key should be the id of the variable and the value the handler
	 * @return static
     * 
     * @example
     * $handlers = [
     *      This'll be resolved passing the $event parameter with the value of the context key 'event' and the $type parameter with the communication type
     *      'evt_name' => function ($event, $type) {
     *          return $event->name;
     *      },
     *      'team' => function ($team, $context)
     * ]
	 */
	public function setHandlers(array $handlers)
	{
		$this->handlers = $handlers;

        return $this;
	}

	/**
	 * Convert the text to the parsed version. Replacing the mentions with the values extracted from the context using the handlers
	 * @param CommunicationType $type
	 * @return mixed
	 */
	public function replace(?CommunicationType $type = null)
    {
        $parsedText = $this->text;

        collect(Variables::getFlatRawVariables())->each(function ($vars) use ($type, &$parsedText) {
            $this->processVariable($vars, $type, $parsedText);
        });

        return $parsedText;
    }

    /**
     * Replacing a variable in the text if exists with the value extracted from the context using the handler
     * @param array<{string, string, string, bool}> $var An array with the id, name, classes and automaticHandling of the variable
     * @param CommunicationType $type The type of the communication to be used in the handler
     * @param mixed $parsedText The reference to the text to be parsed
     * @return void
     */
    protected function processVariable($var, $type, &$parsedText)
    {
        [$id, $name, $classes, $automaticHandling] = $var;

        $exists = strpos($this->text, $this->getMentionHtml($id));

        if ($exists !== false) {
            $handler = $this->handlers[$id] ?? null;

            if ($handler) {
                $args = $this->getHandlerArguments($handler, $type);
                $parsedText = $this->replaceMention($this->text, $id, $handler(...$args));
            } else if ($automaticHandling ) {
                $parsedText = $this->handleAutomaticReplacement($id, $parsedText);
            }
        }
    }

    /**
     * Get the arguments to be passed to the handler using reflection to get the parameters names
     * @param callable $handler
     * @param ?CommunicationType $type
     * @return array
     */
    protected function getHandlerArguments($handler, $type)
    {
        $context = $this->context;

        $reflection = new ReflectionFunction($handler);
        $parameters = $reflection->getParameters();
        $args = [];

        foreach ($parameters as $parameter) {
            $parameterName = $parameter->getName();

            $arg = null;

            if ($parameterName == 'type' || $parameterName == 'context') {
                $arg = $$parameterName;
            } else if (array_key_exists($parameterName, $this->context)) {
                $arg = $this->context[$parameterName];
            }            
            
            $args[] = $arg;
        }

        return $args;
    }

    /**
     * Handle the automatic replacement of a variable in the text. Using {modelName}.{attribute} to get the value from the context
     * @param mixed $id
     * @param mixed $parsedText
     * @return mixed
     */
    protected function handleAutomaticReplacement($id, $parsedText)
    {
        $parts = explode('.', $id);
        $modelName = $parts[0];
        $attribute = $parts[1] ?? null;

        if ($attribute) {
            if (method_exists($this->context[$modelName], $attribute)) {
                $replaceWith = $this->context[$modelName]->$attribute();
            } else {
                $replaceWith = $this->context[$modelName]?->$attribute;
            }
        } else {
            $replaceWith = $this->context[$modelName];
        }

        if (is_callable($replaceWith)) {
            $args = $this->getHandlerArguments($replaceWith, null);

            $replaceWith = $replaceWith(...$args);
        }

        return $this->replaceMention($this->text, $id, $replaceWith);
    }

	// MENTION PARSERS
    /**
     * Get the html representation of a var. Used in ckeditor to identify the mentions
     * @param string $type
     * @return string
     */
	public function getMentionHtml($varName)
	{
		return '<span class="mention" data-mention="' . $varName . '">';
	}

    /**
     * Replace the mention in the text with the value passed
     * @param string $subject The text to be parsed
     * @param string $varName The name of the mention to be replaced
     * @param string $replaceWith The value to replace the mention with
     * @return string
     */
	public function replaceMention($subject, $varName, $replaceWith): string
	{
		$start = strpos($subject, $this->getMentionHtml($varName));

		while ($start > -1) {

			$closeTag = '</span>';
			$end = strpos($subject, $closeTag, $start + strlen($this->getMentionHtml($varName))) + strlen($closeTag);

			$subject = substr_replace($subject, $replaceWith, $start, $end - $start);

			$start = strpos($subject, $this->getMentionHtml($varName));
		}

		return $subject;
	}
}
