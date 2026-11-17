<?php

namespace Condoedge\Communications\Services\Suppression;

use Condoedge\Communications\Services\Suppression\Rules\EmailSuppressionListRule;
use Condoedge\Communications\Services\Suppression\Rules\RecipientPreferenceRule;
use Condoedge\Communications\Services\Suppression\Rules\SuppressionRule;

/** Runs the configured rules in order; the first one that objects decides. One instance per send. */
class SuppressionPipeline
{
    public const DEFAULT_RULES = [
        EmailSuppressionListRule::class,
        RecipientPreferenceRule::class,
    ];

    /** @var SuppressionRule[]|null */
    protected ?array $rules = null;

    /** @param array $communicables */
    public function prime(SuppressionContext $context, array $communicables): void
    {
        foreach ($this->rules() as $rule) {
            $rule->prime($context, $communicables);
        }
    }

    public function reasonFor(SuppressionContext $context, $communicable, ?string $address = null): ?string
    {
        foreach ($this->rules() as $rule) {
            if ($reason = $rule->reasonFor($context, $communicable, $address)) {
                return $reason;
            }
        }

        return null;
    }

    /** @return SuppressionRule[] */
    protected function rules(): array
    {
        return $this->rules ??= array_map(
            fn (string $rule) => app($rule),
            config('kompo-communications.suppression.rules', self::DEFAULT_RULES),
        );
    }
}
