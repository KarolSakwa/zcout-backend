<?php

namespace App\Actions\Scouting;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClaimAnonVotesAction
{
    public function __construct(
        private readonly ClaimAnonIdParser $claimAnonIdParser,
    ) {
    }

    public function execute(Request $request): array
    {
        $parsed = $this->claimAnonIdParser->parse(
            (string) $request->header('X-Zcout-Anon'),
            trim((string) $request->header('X-Zcout-Anon-Legacy')),
        );

        if ($parsed['ok'] !== true) {
            return [
                'ok' => false,
                'status' => 422,
                'body' => [
                    'message' => $parsed['message'],
                ],
            ];
        }

        $claimed = 0;

        foreach ($parsed['ids'] as $anonId) {
            $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

            $claimed += DB::table('votes')
                ->whereNull('user_id')
                ->where('voter_hash', $voterHash)
                ->update([
                    'user_id' => $request->user()->id,
                ]);
        }

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                'claimed' => (int) $claimed,
            ],
        ];
    }
}
