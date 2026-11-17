<?php

namespace Condoedge\Communications\Services\Suppression\Rules;

use Condoedge\Communications\Services\Suppression\SuppressionContext;

/**
 * One reason a recipient may not be contacted. Listed in `kompo-communications.suppression.rules`
 * and resolved once per send, so a rule may keep what prime() loaded on itself.
 */
interface SuppressionRule
{
    /**
     * Load in one go whatever reasonFor() will need for these recipients.
     * Optional for correctness: reasonFor() must still answer for a recipient that was not primed.
     *
     * @param array $communicables
     */
    public function prime(SuppressionContext $context, array $communicables): void;

    /**
     * @param string|null $address the address being considered, null on a channel that has none
     * @return string|null short reason recorded on the recipient row, or null to let it through
     */
    public function reasonFor(SuppressionContext $context, $communicable, ?string $address = null): ?string;
}
