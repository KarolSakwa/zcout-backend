<?php

namespace App\Simulation\Synthetic;

final class SyntheticDecisionProfiles
{
    public const EXPERT = 'expert';
    public const CASUAL = 'casual';
    public const NOISY = 'noisy';

    /**
     * @var list<string>
     */
    public const ALLOWED = [
        self::EXPERT,
        self::CASUAL,
        self::NOISY,
    ];

    public static function isAllowed(string $profile): bool
    {
        return in_array($profile, self::ALLOWED, true);
    }

    public static function listForMessage(): string
    {
        return implode(', ', self::ALLOWED);
    }
}
