<?php

namespace App\Simulation\Synthetic;

use App\Models\SyntheticWorldRuntimeSettings;
use Carbon\CarbonInterface;

class SyntheticWorldRuntime
{
    public function current(): SyntheticWorldRuntimeSettings
    {
        $settings = SyntheticWorldRuntimeSettings::query()->find(SyntheticWorldRuntimeSettings::SINGLETON_ID);
        if ($settings !== null) {
            return $settings;
        }

        return SyntheticWorldRuntimeSettings::query()->create([
            'id' => SyntheticWorldRuntimeSettings::SINGLETON_ID,
            'runtime_enabled' => true,
            'updated_source' => 'auto_create',
        ]);
    }

    public function environmentEnabled(): bool
    {
        return (bool) config('synthetic_world.enabled', false);
    }

    public function runtimeEnabled(): bool
    {
        return (bool) $this->current()->runtime_enabled;
    }

    public function pauseMode(): ?string
    {
        return $this->current()->pause_mode;
    }

    public function effectiveEnabled(): bool
    {
        return $this->environmentEnabled() && $this->runtimeEnabled();
    }

    public function allowsStartingSessions(): bool
    {
        return $this->effectiveEnabled();
    }

    public function allowsAdvancingSessions(): bool
    {
        if (! $this->environmentEnabled()) {
            return false;
        }

        if ($this->runtimeEnabled()) {
            return true;
        }

        return $this->pauseMode() === SyntheticWorldRuntimeSettings::PAUSE_FINISH_ACTIVE;
    }

    public function markRunning(string $source): SyntheticWorldRuntimeSettings
    {
        $settings = $this->current();
        $settings->fill([
            'runtime_enabled' => true,
            'paused_at' => null,
            'pause_mode' => null,
            'updated_source' => $source,
        ]);
        $settings->save();

        return $settings->fresh() ?? $settings;
    }

    public function markPaused(string $source, ?string $pauseMode): SyntheticWorldRuntimeSettings
    {
        $settings = $this->current();
        $settings->fill([
            'runtime_enabled' => false,
            'paused_at' => now(),
            'pause_mode' => $pauseMode,
            'updated_source' => $source,
        ]);
        $settings->save();

        return $settings->fresh() ?? $settings;
    }

    public function markTickStarted(): void
    {
        $settings = $this->current();
        $settings->tick_started_at = now();
        $settings->tick_failed_at = null;
        $settings->last_error = null;
        $settings->save();
    }

    public function markTickFinished(int $durationMs): void
    {
        $settings = $this->current();
        $settings->tick_finished_at = now();
        $settings->last_tick_duration_ms = max(0, $durationMs);
        $settings->tick_failed_at = null;
        $settings->last_error = null;
        $settings->save();
    }

    public function markTickFailed(string $error): void
    {
        $settings = $this->current();
        $settings->tick_failed_at = now();
        $settings->last_error = mb_substr($error, 0, 500);
        $settings->save();
    }

    public function markProgress(?CarbonInterface $at = null): void
    {
        $settings = $this->current();
        $settings->last_progress_at = $at ?? now();
        $settings->save();
    }

    public function runtimeLabel(): string
    {
        if ($this->runtimeEnabled()) {
            return 'running';
        }

        return 'paused';
    }

    public function effectiveLabel(): string
    {
        return $this->effectiveEnabled() ? 'enabled' : 'disabled';
    }
}
