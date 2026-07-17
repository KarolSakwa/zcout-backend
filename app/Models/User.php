<?php

namespace App\Models;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Support\ValidateUserAccessCombination;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'influence_profile',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'influence_profile' => InfluenceProfile::class,
            'is_synthetic' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            app(ValidateUserAccessCombination::class)->validate(
                $user->role,
                $user->influence_profile,
            );

            if ($user->isDirty('is_synthetic')
                && $user->getOriginal('is_synthetic')
                && ! $user->is_synthetic
                && $user->syntheticProfile()->exists()
            ) {
                throw new DomainException(
                    'Cannot unset is_synthetic while the user has a synthetic profile.',
                );
            }
        });
    }

    public function syntheticProfile(): HasOne
    {
        return $this->hasOne(SyntheticUserProfile::class);
    }
}
