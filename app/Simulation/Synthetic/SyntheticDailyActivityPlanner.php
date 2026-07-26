<?php

namespace App\Simulation\Synthetic;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class SyntheticDailyActivityPlanner
{
    /**
     * Deterministic daily session target in [min, max] for user_id + activity_date.
     */
    public function targetSessionsToday(
        int $userId,
        DateTimeInterface|string $activityDate,
        int $sessionsPerDayMin,
        int $sessionsPerDayMax,
    ): int {
        if ($sessionsPerDayMin < 0 || $sessionsPerDayMax < 0) {
            throw new InvalidArgumentException(
                'sessions_per_day bounds must be greater than or equal to 0.',
            );
        }

        if ($sessionsPerDayMax < $sessionsPerDayMin) {
            throw new InvalidArgumentException(
                'sessions_per_day_max must be greater than or equal to sessions_per_day_min.',
            );
        }

        if ($sessionsPerDayMin === $sessionsPerDayMax) {
            return $sessionsPerDayMin;
        }

        $dateKey = $this->normalizeDateKey($activityDate);
        $digest = hash('sha256', 'synthetic-daily-target|'.$userId.'|'.$dateKey);
        $span = $sessionsPerDayMax - $sessionsPerDayMin + 1;
        $bucket = hexdec(substr($digest, 0, 8)) % $span;

        return $sessionsPerDayMin + $bucket;
    }

    /**
     * Deterministic scheduled start within the configured local activity window.
     */
    public function scheduledStartAt(
        int $userId,
        DateTimeInterface|string $activityDate,
        int $dailySessionIndex,
        int $targetSessionsToday,
    ): CarbonImmutable {
        if ($targetSessionsToday <= 0) {
            throw new InvalidArgumentException(
                'targetSessionsToday must be greater than 0.',
            );
        }

        if ($dailySessionIndex < 1 || $dailySessionIndex > $targetSessionsToday) {
            throw new InvalidArgumentException(
                'dailySessionIndex must be between 1 and targetSessionsToday.',
            );
        }

        $timezone = $this->timezone();
        $startHour = (int) config('synthetic_world.activity_start_hour', 7);
        $endHour = (int) config('synthetic_world.activity_end_hour', 18);

        if ($startHour < 0 || $startHour > 23) {
            throw new InvalidArgumentException(
                'synthetic_world.activity_start_hour must be between 0 and 23.',
            );
        }

        if ($endHour < 1 || $endHour > 24) {
            throw new InvalidArgumentException(
                'synthetic_world.activity_end_hour must be between 1 and 24.',
            );
        }

        if ($endHour <= $startHour) {
            throw new InvalidArgumentException(
                'synthetic_world.activity_end_hour must be greater than activity_start_hour.',
            );
        }

        $dayStart = $this->dayStart($activityDate, $timezone);
        $windowStart = $dayStart->addHours($startHour);
        $windowEnd = $dayStart->addHours($endHour);
        $totalSeconds = $windowStart->diffInSeconds($windowEnd);

        if ($totalSeconds <= 0) {
            throw new InvalidArgumentException(
                'Synthetic activity window duration must be positive.',
            );
        }

        $slotStartOffset = intdiv(
            ($dailySessionIndex - 1) * $totalSeconds,
            $targetSessionsToday,
        );

        $slotEndOffset = intdiv(
            $dailySessionIndex * $totalSeconds,
            $targetSessionsToday,
        );

        $slotLength = max(1, $slotEndOffset - $slotStartOffset);

        $dateKey = $this->normalizeDateKey($activityDate);
        $digest = hash(
            'sha256',
            'synthetic-daily-slot|'.$userId.'|'.$dateKey.'|'.$dailySessionIndex,
        );

        $offsetInSlot = hexdec(substr($digest, 0, 8)) % $slotLength;

        return $windowStart->addSeconds(
            $slotStartOffset + $offsetInSlot,
        );
    }

    /**
     * Stable UUID v5 for a world-engine daily session identity.
     */
    public function sessionSeed(
        int $userId,
        DateTimeInterface|string $activityDate,
        int $dailySessionIndex,
    ): string {
        $dateKey = $this->normalizeDateKey($activityDate);
        $name = 'synthetic-world-session|'.$userId.'|'.$dateKey.'|'.$dailySessionIndex;

        return Uuid::uuid5(Uuid::NAMESPACE_URL, $name)->toString();
    }

    public function normalizeDateKey(
        DateTimeInterface|string $activityDate,
    ): string {
        $timezone = $this->timezone();

        if (is_string($activityDate)) {
            return CarbonImmutable::parse($activityDate, $timezone)
                ->toDateString();
        }

        if ($activityDate instanceof CarbonInterface) {
            return $activityDate
                ->copy()
                ->timezone($timezone)
                ->toDateString();
        }

        return CarbonImmutable::instance($activityDate)
            ->timezone($timezone)
            ->toDateString();
    }

    private function dayStart(
        DateTimeInterface|string $activityDate,
        string $timezone,
    ): CarbonImmutable {
        $dateKey = $this->normalizeDateKey($activityDate);

        return CarbonImmutable::parse($dateKey, $timezone)->startOfDay();
    }

    private function timezone(): string
    {
        return (string) config(
            'synthetic_world.timezone',
            config('app.timezone', 'UTC'),
        );
    }
}
