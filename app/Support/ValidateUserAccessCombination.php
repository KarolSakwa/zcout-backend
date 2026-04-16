<?php

namespace App\Support;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use DomainException;

final class ValidateUserAccessCombination
{
    public function __construct(
        private readonly UserAccessPolicy $policy = new UserAccessPolicy(),
    ) {
    }

    public function validate(UserRole $role, InfluenceProfile $profile): void
    {
        if ($this->policy->allows($role, $profile)) {
            return;
        }

        throw new DomainException(
            sprintf(
                'Illegal user access combination: role=%s, influence_profile=%s',
                $role->value,
                $profile->value,
            ),
        );
    }
}
