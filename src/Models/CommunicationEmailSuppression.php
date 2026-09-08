<?php

namespace Condoedge\Communications\Models;

use Condoedge\Utils\Models\Model;
use Kompo\Auth\Contracts\Security\OptsOutOfSecurity;

/**
 * One row per suppressed address. The row survives a resubscribe — `unsubscribed_at` being set is
 * the suppressed state, not the row's existence — because consent history is what CASL asks for.
 *
 * OptsOutOfSecurity is load-bearing: ModelBase carries the HasSecurity plugin, and this list is read
 * from queue workers and a public endpoint with no authenticated user. A read scope there would hide
 * suppression rows and the send would go out, so this table opts out rather than fail open.
 */
class CommunicationEmailSuppression extends Model implements OptsOutOfSecurity
{
    /** The global preference. Never null: NULLs are distinct in a unique index. */
    public const GLOBAL_CATEGORY = '';

    protected $table = 'communication_email_suppressions';

    protected $casts = [
        'reason' => EmailSuppressionReason::class,
        'unsubscribed_at' => 'datetime',
        'resubscribed_at' => 'datetime',
    ];

    public function getSkippedSecurityOperations(): array
    {
        return ['read', 'write', 'delete'];
    }

    // HELPERS

    /** The same person types their address a dozen ways over a lifetime. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    // SCOPES

    public function scopeForEmail($query, string $email)
    {
        return $query->where('email', static::normalizeEmail($email));
    }

    public function scopeGlobal($query)
    {
        return $query->where('category', static::GLOBAL_CATEGORY);
    }

    public function scopeActive($query)
    {
        return $query->whereNotNull('unsubscribed_at');
    }
}
