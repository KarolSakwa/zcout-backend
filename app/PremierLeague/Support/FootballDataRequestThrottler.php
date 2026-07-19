<?php

namespace App\PremierLeague\Support;

final class FootballDataRequestThrottler
{
    /** @var list<float> */
    private array $requestTimestamps = [];

    private ?float $lastRequestAt = null;

    public function __construct(
        private readonly PremierLeagueApiClock $clock,
        private readonly PremierLeagueApiSleeper $sleeper,
        private readonly int $minimumIntervalSeconds,
        private readonly int $maxRequestsPerWindow,
        private readonly int $windowSeconds,
    ) {
    }

    public function waitBeforeNextRequest(): void
    {
        $now = $this->clock->now();

        if ($this->minimumIntervalSeconds > 0 && $this->lastRequestAt !== null) {
            $elapsed = $now - $this->lastRequestAt;
            $intervalWait = $this->minimumIntervalSeconds - $elapsed;
            if ($intervalWait > 0) {
                $this->sleeper->sleep($intervalWait);
                $now = $this->clock->now();
            }
        }

        $this->pruneOldTimestamps($now);

        while ($this->maxRequestsPerWindow > 0 && count($this->requestTimestamps) >= $this->maxRequestsPerWindow) {
            $oldest = $this->requestTimestamps[0];
            $windowWait = ($oldest + $this->windowSeconds) - $now;
            if ($windowWait > 0) {
                $this->sleeper->sleep($windowWait);
                $now = $this->clock->now();
            }

            $this->pruneOldTimestamps($now);
        }
    }

    public function recordRequest(): void
    {
        $now = $this->clock->now();
        $this->requestTimestamps[] = $now;
        $this->lastRequestAt = $now;
    }

  /**
   * @return list<float>
   */
    public function requestTimestamps(): array
    {
        return $this->requestTimestamps;
    }

    private function pruneOldTimestamps(float $now): void
    {
        if ($this->windowSeconds <= 0) {
            return;
        }

        $cutoff = $now - $this->windowSeconds;
        $this->requestTimestamps = array_values(array_filter(
            $this->requestTimestamps,
            static fn (float $timestamp): bool => $timestamp > $cutoff,
        ));
    }
}
