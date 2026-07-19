<?php

namespace App\Models;

use App\Simulation\Synthetic\ValidateSyntheticUserProfile;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyntheticUserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'decision_profile',
        'sessions_per_day_min',
        'sessions_per_day_max',
        'actions_per_session_min',
        'actions_per_session_max',
        'delay_seconds_min',
        'delay_seconds_max',
        'skip_probability',
        'decision_accuracy',
        'noise_level',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'sessions_per_day_min' => 'integer',
            'sessions_per_day_max' => 'integer',
            'actions_per_session_min' => 'integer',
            'actions_per_session_max' => 'integer',
            'delay_seconds_min' => 'integer',
            'delay_seconds_max' => 'integer',
            'skip_probability' => 'float',
            'decision_accuracy' => 'float',
            'noise_level' => 'float',
            'is_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            app(ValidateSyntheticUserProfile::class)->validate([
                'decision_profile' => $profile->decision_profile,
                'sessions_per_day_min' => $profile->sessions_per_day_min,
                'sessions_per_day_max' => $profile->sessions_per_day_max,
                'actions_per_session_min' => $profile->actions_per_session_min,
                'actions_per_session_max' => $profile->actions_per_session_max,
                'delay_seconds_min' => $profile->delay_seconds_min,
                'delay_seconds_max' => $profile->delay_seconds_max,
                'skip_probability' => $profile->skip_probability,
                'decision_accuracy' => $profile->decision_accuracy,
                'noise_level' => $profile->noise_level,
                'is_enabled' => $profile->is_enabled,
            ]);

            $profile->assertBelongsToSyntheticUser();
        });
    }

    private function assertBelongsToSyntheticUser(): void
    {
        $userId = (int) $this->user_id;
        if ($userId <= 0) {
            throw new DomainException(
                'Synthetic user profile can only belong to a user with is_synthetic=true.',
            );
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->is_synthetic) {
            throw new DomainException(
                'Synthetic user profile can only belong to a user with is_synthetic=true.',
            );
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
