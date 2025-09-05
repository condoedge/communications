<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager;

use Condoedge\Communications\Facades\Variables;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Contracts\MentionParserInterface;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Parsers\HtmlMentionParser;
use Illuminate\Support\Facades\Log;
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
    protected $postProcessors = [];
    protected $parsers = [];
    protected $previousParsers = [];
    protected $currentUsageCount = 0;
    protected $maxUsages = 0;

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
     * A generic enhancer to be used to post process the result of the handlers. It will be applied in the order they were set
     * The specific case because i created this was to parse mail elements in the handlers
     * @param array $postProcessors
     * @return static
     */
    public function setPostProcessors(array $postProcessors)
    {
        $this->postProcessors = array_merge($this->postProcessors, $postProcessors);

        return $this;
    }

    /**
     * Set parsers, replacing existing ones
     * @param array<MentionParserInterface> $parsers
     * @param int $uses Number of uses before reverting to previous parsers (0 = permanent)
     * @return static
     */
    public function setParsers(array $parsers, int $uses = 0)
    {
        if ($uses > 0) {
            $this->previousParsers = $this->parsers;
            $this->maxUsages = $uses;
            $this->currentUsageCount = 0;
        }
        
        $this->parsers = $parsers;
        return $this;
    }

    /**
     * Add parsers to existing ones
     * @param array<MentionParserInterface> $parsers
     * @param int $uses Number of uses before reverting (0 = permanent)
     * @return static
     */
    public function addParsers(array $parsers, int $uses = 0)
    {
        if ($uses > 0) {
            $this->previousParsers = $this->parsers;
            $this->maxUsages = $uses;
            $this->currentUsageCount = 0;
        }

        $this->parsers = array_merge($this->parsers, $parsers);
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

        try {
            collect(Variables::getFlatRawVariables())->each(function ($vars) use ($type, &$parsedText) {
                $this->processVariable($vars, $type, parsedText: $parsedText);
            });
        } catch (\Exception $e) {
            // Log the error or handle it as needed
            \Log::error("Error processing variables in MessageContentReplacer: " . $e->getMessage());

            throw new \RuntimeException("Failed to process variables in the text.");
        }

        $this->checkUsageLimit();

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

        foreach ($this->parsers as $parser) {
            $mentionFormat = $parser->getMentionFormat($id);
            $exists = strpos($parsedText, $mentionFormat);

            if ($exists !== false) {
                $handler = $this->handlers[$id] ?? null;
                if ($handler) {
                    $args = $this->getHandlerArguments($handler, $type);
                    $parsedText = $parser->replaceMention($parsedText, $id, $this->handlerCalling($handler, $args));
                } else if ($automaticHandling) {
                    $parsedText = $this->handleAutomaticReplacement($id, $parsedText, $parser);
                }
            }
        }
    }

    protected function handlerCalling($handler, $args)
    {
        $result = $handler(...$args);

        foreach ($this->postProcessors as $processor) {
            $result = $processor($result);
        }

        return $result;
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
    protected function handleAutomaticReplacement($id, $parsedText, $parser)
    {
        $parts = explode('.', $id);
        $modelName = $parts[0];
        $attribute = $parts[1] ?? null;
        $replaceWith = '';

        if (!isset($this->context[$modelName])) {
            Log::warning("Model $modelName not found in context for automatic replacement of variable $id.");

            return $parsedText; // If the model is not in the context, skip replacement
        }

        if ($attribute) {
            if (method_exists($this->context[$modelName], $attribute)) {
                $replaceWith = $this->context[$modelName]->$attribute();
            } elseif (property_exists($this->context[$modelName] ?? new \stdClass, $attribute) || method_exists($this->context[$modelName] ?? new \stdClass, 'getAttribute')) {
                $replaceWith = $this->context[$modelName]?->$attribute;
            }
        } else {
            $replaceWith = $this->context[$modelName];
        }

        if (is_callable($replaceWith)) {
            $args = $this->getHandlerArguments($replaceWith, null);

            $replaceWith = $replaceWith(...$args);
        }

        return $parser->replaceMention($parsedText, $id, $replaceWith);
    }

    /**
     * Check usage limit and revert to previous parsers if needed
     */
    protected function checkUsageLimit()
    {
        if ($this->maxUsages > 0) {
            $this->currentUsageCount++;
            
            if ($this->currentUsageCount >= $this->maxUsages) {
                $this->parsers = $this->previousParsers;
                $this->previousParsers = [];
                $this->maxUsages = 0;
                $this->currentUsageCount = 0;
            }
        }
    }

    public function getVarBuilt($varId, ?string $parserName = null)
    {
        if ($parserName) {
            $parser = collect($this->parsers)->first(function ($p) use ($parserName) {
                return $p->getName() === $parserName;
            });
            
            if ($parser) {
                return $parser->buildVar($varId);
            }
        }

        foreach ($this->parsers as $parser) {
            $result = $parser->buildVar($varId);
            if (!empty($result)) {
                return $result;
            }
        }

        return '';
    }
}
