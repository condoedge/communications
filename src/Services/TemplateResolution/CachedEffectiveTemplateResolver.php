<?php

namespace Condoedge\Communications\Services\TemplateResolution;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Kompo\Auth\Teams\Cache\AuthCacheLayer;

/**
 * Per-request cache decorator over EffectiveTemplateResolverContract.
 *
 * Mirrors CachedEventVisibilityDataResolver / CachedTeamHierarchyService: the pure resolver is
 * wrapped and each (trigger, team) resolution is memoized for the lifetime of one request. The
 * cache layer is flushed at request termination by the auth provider's lifecycle cleanup.
 */
class CachedEffectiveTemplateResolver implements EffectiveTemplateResolverContract
{
    public function __construct(
        protected EffectiveTemplateResolverContract $inner,
        protected AuthCacheLayer $cache,
    ) {
    }

    public function resolve(string $trigger, int $teamId): EffectiveTemplateResolution
    {
        return $this->cache->rememberRequest(
            $this->cacheKey($trigger, $teamId),
            fn () => $this->inner->resolve($trigger, $teamId),
        );
    }

    public function resolveChannel(string $trigger, int $teamId, CommunicationType $type): ?CommunicationTemplate
    {
        $resolution = $this->resolve($trigger, $teamId);

        if (!$resolution->isSendable() || !$resolution->group) {
            return null;
        }

        return $resolution->group->findCommunicationTemplate($type->value);
    }

    public function forget(string $trigger, int $teamId): void
    {
        $this->cache->forget($this->cacheKey($trigger, $teamId));
    }

    protected function cacheKey(string $trigger, int $teamId): string
    {
        return 'eff-template:' . $trigger . ':' . $teamId;
    }
}
