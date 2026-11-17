<?php

namespace Condoedge\Communications\Models;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Contracts\Security\OptsOutOfSecurity;

/**
 * One choice of an address: receive or not, for everything, a group of triggers, or one trigger.
 * No row means "receive". Opts out of security like the suppression list: queue workers read it.
 */
class CommunicationPreference extends Model implements OptsOutOfSecurity
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_GROUP = 'group';
    public const SCOPE_TRIGGER = 'trigger';

    protected $table = 'communication_preferences';

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function getSkippedSecurityOperations(): array
    {
        return ['read', 'write', 'delete'];
    }

    // SCOPES

    /** @param string[] $emails normalized addresses */
    public function scopeForEmails($query, array $emails)
    {
        return $query->whereIn('email', $emails);
    }
}
