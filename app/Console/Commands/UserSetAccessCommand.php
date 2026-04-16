<?php

namespace App\Console\Commands;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\UserAccessPolicy;
use App\Support\ValidateUserAccessCombination;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

final class UserSetAccessCommand extends Command
{
    protected $signature = 'zcout:user-set-access
        {user : User ID or email}
        {--role= : Target role}
        {--profile= : Target influence profile}';

    protected $description = 'Set role and influence profile for a user';

    public function handle(): int
    {
        $userInput = (string) $this->argument('user');

        $user = $this->findUser($userInput);

        if (! $user) {
            $this->error("User not found for selector [{$userInput}].");
            return SymfonyCommand::FAILURE;
        }

        $accessPolicy = app(UserAccessPolicy::class);
        $validator = app(ValidateUserAccessCombination::class);

        $currentRole = UserRole::from((string) $user->getRawOriginal('role'));
        $currentProfile = InfluenceProfile::from((string) $user->getRawOriginal('influence_profile'));

        $roleValue = $this->option('role')
            ?: $this->choice(
                'Select role',
                array_map(static fn (UserRole $role) => $role->value, UserRole::cases()),
                $currentRole->value,
            );

        try {
            $role = UserRole::from((string) $roleValue);
        } catch (Throwable) {
            $this->error("Invalid role [{$roleValue}].");
            return SymfonyCommand::FAILURE;
        }

        $allowedProfiles = $accessPolicy->allowedInfluenceProfilesFor($role);
        $allowedProfileValues = array_map(
            static fn (InfluenceProfile $profile) => $profile->value,
            $allowedProfiles,
        );

        $defaultProfileValue = in_array($currentProfile, $allowedProfiles, true)
            ? $currentProfile->value
            : $allowedProfiles[0]->value;

        $profileValue = $this->option('profile')
            ?: $this->choice(
                'Select influence profile',
                $allowedProfileValues,
                $defaultProfileValue,
            );

        try {
            $profile = InfluenceProfile::from((string) $profileValue);
        } catch (Throwable) {
            $this->error("Invalid influence profile [{$profileValue}].");
            return SymfonyCommand::FAILURE;
        }

        try {
            $validator->validate($role, $profile);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return SymfonyCommand::FAILURE;
        }

        $user->role = $role;
        $user->influence_profile = $profile;
        $user->save();

        $this->info('User access updated.');
        $this->line("User: #{$user->id} ({$user->email})");
        $this->line("Role: {$currentRole->value} -> {$role->value}");
        $this->line("Influence profile: {$currentProfile->value} -> {$profile->value}");

        return SymfonyCommand::SUCCESS;
    }

    private function findUser(string $selector): ?User
    {
        if (ctype_digit($selector)) {
            return User::query()->find((int) $selector);
        }

        return User::query()
            ->where('email', $selector)
            ->first();
    }
}
