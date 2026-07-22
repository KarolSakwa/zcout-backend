<?php

namespace App\Actions\Duels;

use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

final class ResumeLockedDuelAction
{
    public function __construct(
        private BuildNextDuelPayloadAction $buildNextDuelPayloadAction
    ) {
    }

    public function handle(array $context): array
    {
        $voterHash = (string) ($context['voter_hash'] ?? '');
        $voteVoterHash = (string) ($context['vote_voter_hash'] ?? '');

        if ($voterHash === '' || $voteVoterHash === '') {
            return [
                'status' => 'failed',
                'payload' => null,
            ];
        }

        $lockedDuelId = DB::table('voter_duel_locks')
            ->where('voter_hash', $voterHash)
            ->value('duel_id');

        if (!$lockedDuelId) {
            return [
                'status' => 'none',
                'payload' => null,
            ];
        }

        $isLockedSkipped = DB::table('duel_skips')
            ->where('duel_id', $lockedDuelId)
            ->where('voter_hash', $voterHash)
            ->exists();

        $isLockedVoted = DB::table('votes')
            ->where('source', 'duel')
            ->where('duel_id', $lockedDuelId)
            ->where('voter_hash', $voteVoterHash)
            ->exists();

        if ($isLockedSkipped || $isLockedVoted) {
            DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->delete();

            return [
                'status' => 'expired',
                'payload' => null,
            ];
        }

        $lockedDuel = Duel::query()->find($lockedDuelId);
        if (!$lockedDuel) {
            DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->delete();

            return [
                'status' => 'expired',
                'payload' => null,
            ];
        }

        $lockedAttr = Attribute::query()->find($lockedDuel->attribute_id);
        if (!$lockedAttr) {
            DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->delete();

            return [
                'status' => 'expired',
                'payload' => null,
            ];
        }

        $playerIds = [(int) $lockedDuel->player_a_id, (int) $lockedDuel->player_b_id];

        $players = Player::query()
            ->select([
                'id',
                'name',
                'slug',
                'number',
                'club_id',
                'country_id',
                'position_id',
                'fd_name',
                'fd_number',
                'manual_display_name',
                'manual_number',
                'fd_position_id',
                'manual_position_id',
            ])
            ->with([
                'clubRel:id,name,color_primary,color_secondary,color_tertiary',
                'countryRef:id,name,iso2',
                'positionRef:id,short_label,label,key',
                'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label',
            ])
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        $pA = $players->get((int) $lockedDuel->player_a_id);
        $pB = $players->get((int) $lockedDuel->player_b_id);

        if (!$pA || !$pB) {
            DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->delete();

            return [
                'status' => 'expired',
                'payload' => null,
            ];
        }

        $bothInCurrentPremierLeague = Player::query()
            ->whereKey([(int) $pA->id, (int) $pB->id])
            ->inCurrentPremierLeague()
            ->count() === 2;

        if (! $bothInCurrentPremierLeague) {
            DB::table('voter_duel_locks')->where('voter_hash', $voterHash)->delete();

            return [
                'status' => 'expired',
                'payload' => null,
            ];
        }

        $payload = $this->buildNextDuelPayloadAction->handle([
            'attribute' => $lockedAttr,
            'duel' => $lockedDuel,
            'players' => [
                (int) $pA->id => $pA,
                (int) $pB->id => $pB,
            ],
            'matchmaking' => [
                'category' => null,
                'positional_mode' => null,
                'intent' => null,
                'tier' => null,
                'gap_profile' => null,
            ],
            'debug' => null,
        ]);

        return [
            'status' => 'ok',
            'payload' => $payload,
        ];
    }
}
