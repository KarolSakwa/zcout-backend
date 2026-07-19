<?php

namespace App\Simulation\Synthetic;

use App\Models\SyntheticUserSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CancelActiveSyntheticSessionsAction
{
    public const REASON_OPERATOR = 'cancelled_by_operator';

    public const REASON_DAILY_PLAN_RESET = 'daily_plan_reset';

    /**
     * @param  Builder<SyntheticUserSession>|null  $query
     * @return array{cancelled: int, session_ids: list<int>}
     */
    public function execute(?Builder $query = null, string $reason = self::REASON_OPERATOR): array
    {
        $base = $query?->clone() ?? SyntheticUserSession::query();
        $ids = $base
            ->where('status', SyntheticSessionStatuses::ACTIVE)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return ['cancelled' => 0, 'session_ids' => []];
        }

        $now = now();
        DB::table('synthetic_user_sessions')
            ->whereIn('id', $ids)
            ->where('status', SyntheticSessionStatuses::ACTIVE)
            ->update([
                'status' => SyntheticSessionStatuses::CANCELLED,
                'next_action_at' => null,
                'completed_at' => $now,
                'last_action_status' => 'failure',
                'last_action_reason' => $reason,
                'updated_at' => $now,
            ]);

        return [
            'cancelled' => count($ids),
            'session_ids' => $ids,
        ];
    }
}
