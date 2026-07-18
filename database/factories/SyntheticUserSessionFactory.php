<?php

namespace Database\Factories;

use App\Models\SyntheticUserSession;
use App\Models\User;
use App\Simulation\Synthetic\SyntheticSessionStatuses;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyntheticUserSession>
 */
class SyntheticUserSessionFactory extends Factory
{
    protected $model = SyntheticUserSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->synthetic(),
            'status' => SyntheticSessionStatuses::ACTIVE,
            'planned_actions' => 3,
            'completed_actions' => 0,
            'next_action_at' => now(),
            'started_at' => now(),
            'completed_at' => null,
            'session_seed' => (string) Str::uuid(),
            'last_action_status' => null,
            'last_action_reason' => null,
            'activity_date' => null,
            'daily_session_index' => null,
            'scheduled_start_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => SyntheticSessionStatuses::ACTIVE,
            'completed_at' => null,
            'next_action_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => SyntheticSessionStatuses::COMPLETED,
            'completed_actions' => 3,
            'planned_actions' => 3,
            'next_action_at' => null,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => SyntheticSessionStatuses::FAILED,
            'next_action_at' => null,
            'completed_at' => now(),
            'last_action_status' => 'failure',
            'last_action_reason' => 'unexpected_error',
        ]);
    }

    public function due(): static
    {
        return $this->state(fn (): array => [
            'status' => SyntheticSessionStatuses::ACTIVE,
            'next_action_at' => now()->subSecond(),
            'completed_at' => null,
        ]);
    }

    public function notDue(): static
    {
        return $this->state(fn (): array => [
            'status' => SyntheticSessionStatuses::ACTIVE,
            'next_action_at' => now()->addHour(),
            'completed_at' => null,
        ]);
    }

    public function world(string $activityDate, int $dailySessionIndex): static
    {
        return $this->state(fn (): array => [
            'activity_date' => $activityDate,
            'daily_session_index' => $dailySessionIndex,
            'scheduled_start_at' => now(),
        ]);
    }
}
