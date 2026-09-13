<?php

namespace Condoedge\Communications\Models;

/**
 * What kind of mail a trigger produces, which decides whether an unsubscribe silences it.
 * Anything that declares no category resolves to TRANSACTIONAL, so forgetting to classify a
 * trigger can never swallow an invoice or an activation link.
 */
enum CommunicationCategory: string
{
    use \Condoedge\Utils\Models\Traits\EnumKompo;

    /** Sent because the recipient did something. Never suppressed. */
    case TRANSACTIONAL = 'transactional';

    /** Operational reminders and prompts. Suppressible. */
    case NOTIFICATION = 'notification';

    /** Campaigns, announcements, manual blasts. Suppressible. */
    case MARKETING = 'marketing';

    public function label()
    {
        return match ($this) {
            self::TRANSACTIONAL => __('communications.category-transactional'),
            self::NOTIFICATION => __('communications.category-notification'),
            self::MARKETING => __('communications.category-marketing'),
        };
    }

    public function isSuppressible(): bool
    {
        return $this !== self::TRANSACTIONAL;
    }

    /**
     * Probed with method_exists rather than an interface, matching how
     * CommunicationTemplateGroup::getValidCommTypesForTrigger() already probes `acceptsChannel`.
     *
     * @param string|null $trigger trigger FQCN as stored on the template group
     */
    public static function forTrigger(?string $trigger): self
    {
        if (!$trigger || !method_exists($trigger, 'communicationCategory')) {
            return self::TRANSACTIONAL;
        }

        $category = $trigger::communicationCategory();

        return $category instanceof self ? $category : self::TRANSACTIONAL;
    }
}
