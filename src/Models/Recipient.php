<?php

namespace Condoedge\Communications\Models;

use Condoedge\Communications\Services\CommunicationHandlers\Contracts\ChannelAware;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\SmsCommunicable;
use Condoedge\Utils\Models\Model;
use Illuminate\Contracts\Translation\HasLocalePreference;

class Recipient extends Model implements EmailCommunicable, SmsCommunicable, ChannelAware, HasLocalePreference
{
    protected $table = 'communication_recipients';

    protected $fillable = ['email', 'phone', 'name', 'language', 'team_id'];

    // BOOT
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($recipient) {
            if (empty($recipient->email) && empty($recipient->phone)) {
                abort(422, 'recipient-must-have-email-or-phone');
            }
        });
    }

    // FACTORIES
    /**
     * Idempotently fetch or create a Recipient scoped to the current team by email.
     *
     * Team scoping mirrors `CommunicationTemplateGroup::deletable()` which uses
     * `auth()->user()->team_id` as the canonical "current team" source in this
     * package.
     */
    public static function findOrCreateByEmail(string $email, array $attrs = []): self
    {
        return static::firstOrCreate(
            [
                'team_id' => auth()->user()?->team_id,
                'email' => $email,
            ],
            $attrs
        );
    }

    /**
     * Idempotently fetch or create a Recipient scoped to the current team by phone.
     */
    public static function findOrCreateByPhone(string $phone, array $attrs = []): self
    {
        return static::firstOrCreate(
            [
                'team_id' => auth()->user()?->team_id,
                'phone' => $phone,
            ],
            $attrs
        );
    }

    // COMMUNICABLE CONTRACT
    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getContextKey(): string
    {
        return 'recipient';
    }

    public function label(): string
    {
        return $this->name ?: ($this->email ?: ($this->phone ?: ''));
    }

    // LOCALE
    public function preferredLocale(): ?string
    {
        return $this->language;
    }

    // CHANNEL-AWARE
    public function acceptsChannel(string $channelInterface): bool
    {
        return match ($channelInterface) {
            EmailCommunicable::class => !empty($this->email),
            SmsCommunicable::class   => !empty($this->phone),
            default                  => false,
        };
    }

    // SCOPES
    public function scopeValidForCommunication($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('email')->orWhereNotNull('phone');
        });
    }

    public function scopeSearch($query, $search)
    {
        $like = '%' . $search . '%';

        return $query->where(function ($q) use ($like) {
            $q->where('email', 'LIKE', $like)
                ->orWhere('phone', 'LIKE', $like)
                ->orWhere('name', 'LIKE', $like);
        });
    }
}
