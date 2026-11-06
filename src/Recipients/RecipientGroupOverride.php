<?php

namespace Condoedge\Communications\Recipients;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\Communicable;

/**
 * RecipientOverride for a model that must be written to at several addresses in one message.
 */
class RecipientGroupOverride extends RecipientOverride
{
    /** @var string[] */
    protected array $emailGroup = [];

    /** @param  string[]  $emails */
    public static function forGroup(Communicable $inner, array $emails): static
    {
        $override = new static($inner);
        $override->emailGroup = array_values($emails);

        return $override;
    }

    /** @return string[] */
    public function getEmailGroup(): array
    {
        return $this->emailGroup;
    }

    /** Identifies the group in logs and dedup keys. Mail goes to getEmailGroup(). */
    public function getEmail()
    {
        return implode(', ', $this->emailGroup);
    }
}
