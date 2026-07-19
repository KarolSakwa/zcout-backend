<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Models\User;
use App\Simulation\Synthetic\SyntheticPoolIdentity;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SyntheticPoolUserInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_user_has_null_pool_metadata(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->synthetic_pool_key);
        $this->assertNull($user->synthetic_pool_index);
        $this->assertFalse($user->is_synthetic);
    }

    public function test_manual_synthetic_user_without_pool_metadata_is_legal(): void
    {
        $user = User::factory()->synthetic()->create();

        $this->assertTrue($user->is_synthetic);
        $this->assertNull($user->synthetic_pool_key);
        $this->assertNull($user->synthetic_pool_index);
        $this->assertNotNull($user->syntheticProfile);
    }

    public function test_pool_key_and_index_must_be_set_together(): void
    {
        $user = User::factory()->create(['is_synthetic' => true]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('both be set or both be null');

        $user->synthetic_pool_key = 'default';
        $user->synthetic_pool_index = null;
        $user->save();
    }

    public function test_managed_member_requires_is_synthetic_true(): void
    {
        $user = User::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('is_synthetic=true');

        $user->synthetic_pool_key = 'default';
        $user->synthetic_pool_index = 1;
        $user->save();
    }

    public function test_unique_pool_index_constraint(): void
    {
        User::factory()->syntheticPoolMember('default', 1)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->syntheticPoolMember('default', 1)->create([
            'email' => 'other-default-0001@zcout.local',
        ]);
    }

    public function test_multiple_null_pool_memberships_are_legal(): void
    {
        User::factory()->count(2)->create();
        User::factory()->synthetic()->create();
        User::factory()->synthetic()->create();

        $this->assertSame(4, User::query()->whereNull('synthetic_pool_key')->count());
    }

    public function test_deleting_managed_user_cascades_profile_and_sessions(): void
    {
        $user = User::factory()->syntheticPoolMember('default', 1)->create();
        $profileId = $user->syntheticProfile->id;
        $session = $user->syntheticSessions()->create([
            'status' => 'active',
            'planned_actions' => 1,
            'completed_actions' => 0,
            'next_action_at' => now(),
            'started_at' => now(),
            'session_seed' => '11111111-1111-4111-8111-111111111111',
        ]);

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('synthetic_user_profiles', ['id' => $profileId]);
        $this->assertDatabaseMissing('synthetic_user_sessions', ['id' => $session->id]);
    }

    public function test_identity_email_and_name_are_deterministic(): void
    {
        $identity = app(SyntheticPoolIdentity::class);

        $this->assertSame(
            'synthetic+default-0001@zcout.local',
            $identity->email('default', 1),
        );
        $this->assertSame(
            'Synthetic Scout default 0001',
            $identity->displayName('default', 1),
        );
    }

    public function test_pool_member_uses_legal_access_combination(): void
    {
        $user = User::factory()->syntheticPoolMember('default', 2, 'expert')->create();

        $this->assertSame(UserRole::USER, $user->role);
        $this->assertSame(InfluenceProfile::USER_DEFAULT, $user->influence_profile);
        $this->assertSame('expert', $user->syntheticProfile->decision_profile);
    }
}
