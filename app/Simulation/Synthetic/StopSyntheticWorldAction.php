<?php

namespace App\Simulation\Synthetic;

use App\Models\SyntheticWorldRuntimeSettings;
use DomainException;

final class StopSyntheticWorldAction
{
    public function __construct(
        private readonly SyntheticWorldRuntime $runtime,
        private readonly CancelActiveSyntheticSessionsAction $cancelActiveSyntheticSessionsAction,
    ) {
    }

    /**
     * @return array{
     *     runtime: \App\Models\SyntheticWorldRuntimeSettings,
     *     cancelled: int,
     *     pause_mode: string|null,
     *     warnings: list<string>
     * }
     */
    public function execute(bool $finishActive = false, bool $cancelActive = false): array
    {
        if ($finishActive && $cancelActive) {
            throw new DomainException('--finish-active and --cancel-active are mutually exclusive.');
        }

        $cancelled = 0;
        $warnings = [];
        $pauseMode = null;

        if ($cancelActive) {
            $result = $this->cancelActiveSyntheticSessionsAction->execute(
                reason: CancelActiveSyntheticSessionsAction::REASON_OPERATOR,
            );
            $cancelled = $result['cancelled'];
            $pauseMode = SyntheticWorldRuntimeSettings::PAUSE_CANCEL_ACTIVE;
            $warnings[] = sprintf('Cancelled %d active session(s).', $cancelled);
        } elseif ($finishActive) {
            $pauseMode = SyntheticWorldRuntimeSettings::PAUSE_FINISH_ACTIVE;
            $warnings[] = 'New sessions will not start; active sessions may finish.';
        } else {
            $pauseMode = null;
            $warnings[] = 'Runtime paused: no new sessions and no advances until start.';
        }

        $runtime = $this->runtime->markPaused('cli:stop', $pauseMode);

        return [
            'runtime' => $runtime,
            'cancelled' => $cancelled,
            'pause_mode' => $pauseMode,
            'warnings' => $warnings,
        ];
    }
}
