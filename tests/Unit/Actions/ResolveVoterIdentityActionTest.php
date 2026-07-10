<?php

namespace Tests\Unit\Actions;

use App\Actions\ResolveVoterIdentityAction;
use App\Data\ActionFailure;
use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResolveVoterIdentityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_anonymous_voter_from_header(): void
    {
        $request = Request::create('/api/votes', 'POST', server: [
            'HTTP_X_ZCOUT_ANON' => 'anon-unit-test-1',
        ]);

        $identity = app(ResolveVoterIdentityAction::class)->execute($request);

        $this->assertNull($identity->userId);
        $this->assertFalse($identity->isAuthenticated);
        $this->assertSame(['anon-unit-test-1'], $identity->lockKeys);
        $this->assertSame('anon-unit-test-1', $identity->lockKey);
        $this->assertSame(
            hash_hmac('sha256', 'anon-unit-test-1', (string) config('app.key')),
            $identity->voterHash,
        );
    }

    public function test_it_prefers_anon_header_over_authenticated_user_for_lock_key(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::USER,
            'influence_profile' => InfluenceProfile::USER_DEFAULT,
        ]);

        Sanctum::actingAs($user);

        $request = Request::create('/api/votes', 'POST', server: [
            'HTTP_X_ZCOUT_ANON' => 'anon-unit-test-2',
        ]);

        $identity = app(ResolveVoterIdentityAction::class)->execute($request);

        $this->assertSame($user->id, $identity->userId);
        $this->assertTrue($identity->isAuthenticated);
        $this->assertSame(['anon-unit-test-2', 'user:' . $user->id], $identity->lockKeys);
        $this->assertSame('anon-unit-test-2', $identity->lockKey);
        $this->assertSame(
            hash_hmac('sha256', 'anon-unit-test-2', (string) config('app.key')),
            $identity->voterHash,
        );
    }

    public function test_it_returns_action_failure_when_voter_identity_is_missing(): void
    {
        $request = Request::create('/api/votes', 'POST');

        $result = app(ResolveVoterIdentityAction::class)->execute($request);

        $this->assertInstanceOf(ActionFailure::class, $result);
        $this->assertSame(400, $result->status);
        $this->assertSame('Missing voter id.', $result->message);
    }
}
