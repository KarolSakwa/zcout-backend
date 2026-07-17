<?php

namespace App\Simulation\Synthetic;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunSyntheticUserSessionAction
{
    public function __construct(
        private readonly ExecuteSyntheticDuelAction $executeSyntheticDuelAction,
        private readonly RunWithAuthenticatedUser $runWithAuthenticatedUser,
    ) {
    }

    /**
     * @param callable(SyntheticSessionActionResult): void|null $onAction
     */
    public function execute(
        User $user,
        string $profile,
        int $actions,
        string $sessionId,
        ?callable $onAction = null,
    ): SyntheticSessionSummary {
        $votes = 0;
        $skips = 0;
        $failures = 0;
        $actionIndex = 0;

        try {
            return $this->runWithAuthenticatedUser->execute($user, function () use (
                $user,
                $profile,
                $actions,
                $sessionId,
                $onAction,
                &$votes,
                &$skips,
                &$failures,
                &$actionIndex,
            ): SyntheticSessionSummary {
                for ($actionIndex = 1; $actionIndex <= $actions; $actionIndex++) {
                    $result = $this->executeSyntheticDuelAction->execute(
                        user: $user,
                        decisionProfile: $profile,
                        sessionSeed: $sessionId,
                        actionIndex: $actionIndex,
                        plannedActions: $actions,
                    );

                    if ($result->status === 'ok' && $result->decision === 'vote') {
                        $votes++;
                    } elseif ($result->status === 'ok' && $result->decision === 'skip') {
                        $skips++;
                    } elseif ($result->status === 'failure') {
                        $failures++;
                    }

                    if ($onAction !== null) {
                        $onAction($result);
                    }
                }

                return new SyntheticSessionSummary(
                    votes: $votes,
                    skips: $skips,
                    failures: $failures,
                    completed: true,
                );
            });
        } catch (Throwable $exception) {
            Log::error('synthetic.session.unexpected_error', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'action_index' => $actionIndex,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
