<?php

namespace App\PremierLeague\Support;

use Illuminate\Http\Client\Response;

final class FootballDataRateLimitWaitParser
{
    public static function parseSeconds(Response $response, int $fallbackSeconds): int
    {
        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== null && $retryAfter !== '' && is_numeric($retryAfter)) {
            return max(0, (int) $retryAfter);
        }

        $body = $response->body();
        if (preg_match('/wait\s+(\d+)\s+seconds/i', $body, $matches) === 1) {
            return max(0, (int) $matches[1]);
        }

        return max(0, $fallbackSeconds);
    }
}
