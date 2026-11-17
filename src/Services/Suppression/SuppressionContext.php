<?php

namespace Condoedge\Communications\Services\Suppression;

use Condoedge\Communications\Models\CommunicationCategory;

/** What one send is: its channel and the trigger behind it. Shared by every rule of that send. */
final class SuppressionContext
{
    public readonly CommunicationCategory $category;

    /**
     * @param class-string $channel channel contract FQCN (e.g. EmailCommunicable::class)
     * @param string|null $trigger trigger FQCN as stored on the template group
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?string $trigger = null,
    ) {
        $this->category = CommunicationCategory::forTrigger($trigger);
    }

    public function canBeTakenOff(): bool
    {
        return CommunicationCategory::canBeTakenOff($this->trigger);
    }
}
