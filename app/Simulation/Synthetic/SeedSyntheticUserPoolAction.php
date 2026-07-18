<?php

namespace App\Simulation\Synthetic;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Models\SyntheticUserProfile;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SeedSyntheticUserPoolAction
{
    public const MAX_COUNT = 1000;

    public function __construct(
        private readonly SyntheticPoolIdentity $identity,
        private readonly SyntheticPoolProfileAllocator $allocator,
    ) {
    }

    public function execute(
        string $poolKey,
        int $targetCount,
        int $expertPercent,
        int $casualPercent,
        int $noisyPercent,
        bool $dryRun = false,
    ): SyntheticUserPoolSeedResult {
        $this->identity->assertValidPoolKey($poolKey);
        $this->assertTargetCount($targetCount);
        $this->allocator->assertDistribution($expertPercent, $casualPercent, $noisyPercent);

        $result = new SyntheticUserPoolSeedResult(
            poolKey: $poolKey,
            targetCount: $targetCount,
            dryRun: $dryRun,
        );

        for ($index = 1; $index <= $targetCount; $index++) {
            $member = $this->findPoolMember($poolKey, $index);

            if ($member === null) {
                $decisionProfile = $this->allocator->allocate(
                    $poolKey,
                    $index,
                    $expertPercent,
                    $casualPercent,
                    $noisyPercent,
                );

                $emailConflict = $this->emailConflictReason($poolKey, $index);
                if ($emailConflict !== null) {
                    $result->recordConflict($index, $emailConflict);
                    continue;
                }

                if ($dryRun) {
                    $result->recordWouldCreateProfile($decisionProfile);
                    continue;
                }

                try {
                    $this->createMember($poolKey, $index, $decisionProfile);
                    $result->recordCreatedProfile($decisionProfile);
                } catch (UniqueConstraintViolationException) {
                    $this->handleCreateRace($poolKey, $index, $result);
                } catch (Throwable $exception) {
                    if ($this->isUniqueViolation($exception)) {
                        $this->handleCreateRace($poolKey, $index, $result);
                        continue;
                    }

                    throw $exception;
                }

                continue;
            }

            $conflictReason = $this->validateExistingMember($member, $poolKey, $index);
            if ($conflictReason !== null) {
                $result->recordConflict($index, $conflictReason);
                continue;
            }

            $result->existingValid++;
        }

        $result->poolAlreadyAboveTarget = $this->countValidManagedMembers($poolKey) > $targetCount;

        return $result;
    }

    private function assertTargetCount(int $targetCount): void
    {
        if ($targetCount < 1) {
            throw new DomainException('The --count option must be a positive integer.');
        }

        if ($targetCount > self::MAX_COUNT) {
            throw new DomainException(sprintf(
                'The --count option must not exceed %d.',
                self::MAX_COUNT,
            ));
        }
    }

    private function findPoolMember(string $poolKey, int $index): ?User
    {
        return User::query()
            ->with('syntheticProfile')
            ->where('synthetic_pool_key', $poolKey)
            ->where('synthetic_pool_index', $index)
            ->first();
    }

    private function emailConflictReason(string $poolKey, int $index): ?string
    {
        $email = $this->identity->email($poolKey, $index);
        $owner = User::query()->where('email', $email)->first();
        if ($owner === null) {
            return null;
        }

        if ($owner->synthetic_pool_key === $poolKey && (int) $owner->synthetic_pool_index === $index) {
            return null;
        }

        return 'email_owned_by_other_user';
    }

    private function validateExistingMember(User $member, string $poolKey, int $index): ?string
    {
        if ($member->synthetic_pool_key !== $poolKey) {
            return 'pool_key_mismatch';
        }

        if ((int) $member->synthetic_pool_index !== $index) {
            return 'pool_index_mismatch';
        }

        if (! $member->is_synthetic) {
            return 'is_synthetic_false';
        }

        $expectedEmail = $this->identity->email($poolKey, $index);
        if ($member->email !== $expectedEmail) {
            return 'email_mismatch';
        }

        $profile = $member->relationLoaded('syntheticProfile')
            ? $member->syntheticProfile
            : $member->syntheticProfile()->first();

        if ($profile === null) {
            return 'missing_profile';
        }

        if (! $profile->is_enabled) {
            return 'profile_disabled';
        }

        if (! SyntheticDecisionProfiles::isAllowed((string) $profile->decision_profile)) {
            return 'invalid_decision_profile';
        }

        $profileCount = SyntheticUserProfile::query()->where('user_id', $member->id)->count();
        if ($profileCount !== 1) {
            return 'profile_count_invalid';
        }

        return null;
    }

    private function createMember(string $poolKey, int $index, string $decisionProfile): User
    {
        return DB::transaction(function () use ($poolKey, $index, $decisionProfile): User {
            $user = new User();
            $user->forceFill([
                'name' => $this->identity->displayName($poolKey, $index),
                'email' => $this->identity->email($poolKey, $index),
                'password' => Str::password(32),
                'email_verified_at' => now(),
                'role' => UserRole::USER,
                'influence_profile' => InfluenceProfile::USER_DEFAULT,
                'is_synthetic' => true,
                'synthetic_pool_key' => $poolKey,
                'synthetic_pool_index' => $index,
            ]);
            $user->save();

            $user->syntheticProfile()->create(array_merge(
                SyntheticUserProfileDefaults::attributes(),
                [
                    'decision_profile' => $decisionProfile,
                    'is_enabled' => true,
                ],
            ));

            return $user->fresh(['syntheticProfile']) ?? $user;
        });
    }

    private function handleCreateRace(string $poolKey, int $index, SyntheticUserPoolSeedResult $result): void
    {
        $member = $this->findPoolMember($poolKey, $index);
        if ($member === null) {
            $result->recordConflict($index, 'unique_violation_unresolved');

            return;
        }

        $conflictReason = $this->validateExistingMember($member, $poolKey, $index);
        if ($conflictReason !== null) {
            $result->recordConflict($index, $conflictReason);

            return;
        }

        $result->existingValid++;
    }

    private function countValidManagedMembers(string $poolKey): int
    {
        $managed = User::query()
            ->where('synthetic_pool_key', $poolKey)
            ->whereNotNull('synthetic_pool_index')
            ->with('syntheticProfile')
            ->get();

        $valid = 0;
        foreach ($managed as $member) {
            if ($this->validateExistingMember($member, $poolKey, (int) $member->synthetic_pool_index) === null) {
                $valid++;
            }
        }

        return $valid;
    }

    private function isUniqueViolation(Throwable $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'users_synthetic_pool_membership_unique')
            || str_contains($message, 'users_email_unique')
            || str_contains($message, 'duplicate key')
            || str_contains($message, 'Unique violation');
    }
}
