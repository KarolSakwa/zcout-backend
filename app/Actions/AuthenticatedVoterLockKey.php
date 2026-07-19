<?php

namespace App\Actions;

/**
 * Canonical matchmaking / skip / lock key for an authenticated user.
 * Votes store HMAC of this value; locks and duel_skips store the raw key.
 */
final class AuthenticatedVoterLockKey
{
    public static function forUserId(int $userId): string
    {
        return 'user:'.$userId;
    }
}
