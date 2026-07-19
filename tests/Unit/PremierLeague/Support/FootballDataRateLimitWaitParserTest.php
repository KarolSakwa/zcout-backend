<?php

namespace Tests\Unit\PremierLeague\Support;

use App\PremierLeague\Support\FootballDataRateLimitWaitParser;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class FootballDataRateLimitWaitParserTest extends TestCase
{
    public function test_it_reads_retry_after_header(): void
    {
        $response = new Response(new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '55'], 'Too many requests'));

        $this->assertSame(55, FootballDataRateLimitWaitParser::parseSeconds($response, 60));
    }

    public function test_it_parses_wait_seconds_from_body(): void
    {
        $response = new Response(new \GuzzleHttp\Psr7\Response(429, [], 'You reached your request limit. Wait 55 seconds.'));

        $this->assertSame(55, FootballDataRateLimitWaitParser::parseSeconds($response, 60));
    }

    public function test_it_uses_fallback_when_wait_time_is_unknown(): void
    {
        $response = new Response(new \GuzzleHttp\Psr7\Response(429, [], 'Too many requests'));

        $this->assertSame(60, FootballDataRateLimitWaitParser::parseSeconds($response, 60));
    }
}
