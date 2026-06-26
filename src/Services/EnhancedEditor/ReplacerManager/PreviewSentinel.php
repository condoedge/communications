<?php

namespace Condoedge\Communications\Services\EnhancedEditor\ReplacerManager;

/**
 * Permissive stand-in passed for every non-type argument when previewing a variable's replacer
 * (see MessageContentReplacer::isLinkVariable). It stays truthy and chainable so a handler can run
 * far enough to build its element, while exposing no real data.
 */
class PreviewSentinel
{
    public function __get($name)
    {
        return $this;
    }

    public function __call($name, $arguments)
    {
        return $this;
    }

    public function __invoke()
    {
        return $this;
    }

    public function __toString(): string
    {
        return '';
    }
}
