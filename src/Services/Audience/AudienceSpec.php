<?php

namespace Condoedge\Communications\Services\Audience;

use Condoedge\Crm\Models\PersonTeamTypeEnum;
use Kompo\Auth\Models\Teams\PermissionTypeEnum;

/**
 * Immutable description of "who should receive this communication".
 *
 * Mirrors the role the event-audience rows play for events: a small value object the
 * CommunicationAudienceService consumes to build the recipient set. Defaults describe a
 * plain team broadcast — every active member of the team and its descendant teams.
 *
 * @property-read int[] $teams anchor team ids the audience is built from
 * @property-read PersonTeamTypeEnum[]|null $memberTypes null = all member types; otherwise restrict
 */
class AudienceSpec
{
    /**
     * @param int[] $teams
     * @param PersonTeamTypeEnum[]|null $memberTypes
     */
    public function __construct(
        public readonly array $teams,
        public readonly bool $includeDescendants = true,
        public readonly ?array $memberTypes = null,
        public readonly bool $includeStaff = false,
        public readonly bool $includeGuardians = false,
        public readonly PermissionTypeEnum $staffPermissionType = PermissionTypeEnum::READ,
    ) {
    }

    /**
     * @param int[] $teams
     */
    public static function forTeams(array $teams): self
    {
        return new self(array_values(array_map('intval', $teams)));
    }

    /**
     * @param PersonTeamTypeEnum[] $memberTypes
     */
    public function withMemberTypes(array $memberTypes): self
    {
        return new self(
            $this->teams,
            $this->includeDescendants,
            $memberTypes ?: null,
            $this->includeStaff,
            $this->includeGuardians,
            $this->staffPermissionType,
        );
    }

    public function withoutDescendants(): self
    {
        return new self(
            $this->teams,
            false,
            $this->memberTypes,
            $this->includeStaff,
            $this->includeGuardians,
            $this->staffPermissionType,
        );
    }

    public function withStaff(PermissionTypeEnum $type = PermissionTypeEnum::READ): self
    {
        return new self(
            $this->teams,
            $this->includeDescendants,
            $this->memberTypes,
            true,
            $this->includeGuardians,
            $type,
        );
    }

    public function withGuardians(): self
    {
        return new self(
            $this->teams,
            $this->includeDescendants,
            $this->memberTypes,
            $this->includeStaff,
            true,
            $this->staffPermissionType,
        );
    }
}
