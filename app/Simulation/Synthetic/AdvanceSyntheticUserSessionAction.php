<?php

namespace App\Simulation\Synthetic;

use App\Models\SyntheticUserProfile;
use App\Models\SyntheticUserSession;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AdvanceSyntheticUserSessionAction
{
    public function __construct(
        private readonly ExecuteSyntheticDuelAction $executeSyntheticDuelAction,
        private readonly RandomIntRange $randomIntRange,
        private readonly RunWithAuthenticatedUser $runWithAuthenticatedUser,
    ) {
    }

    public function execute(int|SyntheticUserSession $session): AdvanceSyntheticUserSessionResult
    {
        $sessionId = $session instanceof SyntheticUserSession
            ? (int) $session->id
            : (int) $session;

        $unexpected = null;
        $result = null;

        DB::transaction(function () use ($sessionId, &$unexpected, &$result): void {
            /** @var SyntheticUserSession|null $locked */
            $locked = SyntheticUserSession::query()
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new DomainException(sprintf('Synthetic session [%d] was not found.', $sessionId));
            }

            if ($locked->isCompleted()) {
                throw new DomainException(sprintf(
                    'Cannot advance completed synthetic session [%d].',
                    $locked->id,
                ));
            }

            if ($locked->isFailed()) {
                throw new DomainException(sprintf(
                    'Cannot advance failed synthetic session [%d].',
                    $locked->id,
                ));
            }

            if ($locked->isCancelled()) {
                throw new DomainException(sprintf(
                    'Cannot advance cancelled synthetic session [%d].',
                    $locked->id,
                ));
            }

            if (! $locked->isActive()) {
                throw new DomainException(sprintf(
                    'Synthetic session [%d] is not active.',
                    $locked->id,
                ));
            }

            if ($locked->completed_actions >= $locked->planned_actions) {
                throw new DomainException(sprintf(
                    'Synthetic session [%d] has no remaining actions.',
                    $locked->id,
                ));
            }

            if ($locked->next_action_at === null || $locked->next_action_at->gt(now())) {
                throw new DomainException(sprintf(
                    'Synthetic session [%d] is not due yet.',
                    $locked->id,
                ));
            }

            $user = User::query()->with('syntheticProfile')->find((int) $locked->user_id);
            if ($user === null) {
                throw new DomainException(sprintf(
                    'Synthetic session [%d] user was not found.',
                    $locked->id,
                ));
            }

            $profile = $user->syntheticProfile;
            if ($profile === null) {
                throw new DomainException(sprintf(
                    'Synthetic user [%d] does not have a profile.',
                    $user->id,
                ));
            }

            $actionIndex = (int) $locked->completed_actions + 1;
            $sessionSeed = (string) $locked->session_seed;

            $this->runWithAuthenticatedUser->execute($user, function () use (
                $user,
                $sessionSeed,
                $actionIndex,
                $locked,
                $profile,
                &$result,
                &$unexpected,
            ): void {
                try {
                    $actionResult = $this->executeSyntheticDuelAction->execute(
                        user: $user,
                        profile: $profile,
                        sessionSeed: $sessionSeed,
                        actionIndex: $actionIndex,
                        plannedActions: (int) $locked->planned_actions,
                    );

                    $this->applyActionResult($locked, $profile, $actionResult);
                    $locked->save();

                    $result = new AdvanceSyntheticUserSessionResult(
                        action: $actionResult,
                        session: $locked->fresh() ?? $locked,
                    );
                } catch (Throwable $exception) {
                    $this->markSessionFailed($locked);
                    $locked->save();

                    Log::error('synthetic.session.unexpected_error', [
                        'session_id' => $locked->id,
                        'user_id' => $locked->user_id,
                        'action_index' => $actionIndex,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);

                    $unexpected = $exception;
                }
            });
        });

        if ($unexpected !== null) {
            throw $unexpected;
        }

        assert($result instanceof AdvanceSyntheticUserSessionResult);

        return $result;
    }

    private function applyActionResult(
        SyntheticUserSession $session,
        SyntheticUserProfile $profile,
        SyntheticSessionActionResult $actionResult,
    ): void {
        $session->last_action_status = $actionResult->status;
        $session->last_action_reason = $actionResult->reason;

        if ($actionResult->status === 'ok') {
            $session->completed_actions = (int) $session->completed_actions + 1;

            if ($session->completed_actions >= $session->planned_actions) {
                $session->status = SyntheticSessionStatuses::COMPLETED;
                $session->completed_at = now();
                $session->next_action_at = null;

                return;
            }
        }

        $delaySeconds = $this->randomIntRange->between(
            (int) $profile->delay_seconds_min,
            (int) $profile->delay_seconds_max,
        );

        $session->status = SyntheticSessionStatuses::ACTIVE;
        $session->next_action_at = now()->addSeconds($delaySeconds);
        $session->completed_at = null;
    }

    private function markSessionFailed(SyntheticUserSession $session): void
    {
        $session->status = SyntheticSessionStatuses::FAILED;
        $session->completed_at = now();
        $session->next_action_at = null;
        $session->last_action_status = 'failure';
        $session->last_action_reason = 'unexpected_error';
    }
}
