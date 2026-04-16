<?php

namespace Tests\Unit;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Support\UserAccessPolicy;
use PHPUnit\Framework\TestCase;

final class UserAccessPolicyTest extends TestCase
{
    public function test_it_allows_legal_combinations(): void
    {
        $policy = new UserAccessPolicy();

        $this->assertTrue($policy->allows(UserRole::USER, InfluenceProfile::USER_DEFAULT));
        $this->assertTrue($policy->allows(UserRole::SCOUT_FOUNDER, InfluenceProfile::SCOUT_FOUNDER));
        $this->assertTrue($policy->allows(UserRole::ADMIN, InfluenceProfile::USER_DEFAULT));
        $this->assertTrue($policy->allows(UserRole::ADMIN, InfluenceProfile::SCOUT_FOUNDER));
    }

    public function test_it_rejects_illegal_combinations(): void
    {
        $policy = new UserAccessPolicy();

        $this->assertFalse($policy->allows(UserRole::USER, InfluenceProfile::SCOUT_FOUNDER));
        $this->assertFalse($policy->allows(UserRole::SCOUT_FOUNDER, InfluenceProfile::USER_DEFAULT));
    }

    public function test_it_returns_allowed_profiles_for_user_role(): void
    {
        $policy = new UserAccessPolicy();

        $this->assertSame(
            [InfluenceProfile::USER_DEFAULT],
            $policy->allowedInfluenceProfilesFor(UserRole::USER),
        );
    }

    public function test_it_returns_allowed_profiles_for_scout_founder_role(): void
    {
        $policy = new UserAccessPolicy();

        $this->assertSame(
            [InfluenceProfile::SCOUT_FOUNDER],
            $policy->allowedInfluenceProfilesFor(UserRole::SCOUT_FOUNDER),
        );
    }

    public function test_it_returns_allowed_profiles_for_admin_role(): void
    {
        $policy = new UserAccessPolicy();

        $this->assertSame(
            [InfluenceProfile::USER_DEFAULT, InfluenceProfile::SCOUT_FOUNDER],
            $policy->allowedInfluenceProfilesFor(UserRole::ADMIN),
        );
    }
}
