<?php

namespace App\Support;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;

final class UserAccessPolicy
{
    public function allows(UserRole $role, InfluenceProfile $profile): bool
    {
        return in_array($profile, $this->allowedInfluenceProfilesFor($role), true);
    }

    public function allowedInfluenceProfilesFor(UserRole $role): array
    {
        return match ($role) {
            UserRole::USER => [
                InfluenceProfile::USER_DEFAULT,
            ],
            UserRole::SCOUT_FOUNDER => [
                InfluenceProfile::SCOUT_FOUNDER,
            ],
            UserRole::ADMIN => [
                InfluenceProfile::USER_DEFAULT,
                InfluenceProfile::SCOUT_FOUNDER,
            ],
        };
    }
}
