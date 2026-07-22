<?php

namespace App\Http\Controllers\Api;

use App\Actions\ScoutReports\GetScoutReportAttributesAction;
use App\Actions\Ratings\StoreDirectVoteAction;
use App\Actions\Duels\StoreDuelVoteAction;
use App\Actions\ScoutReports\SubmitScoutReportAction;
use App\Http\Controllers\Controller;
use App\Requests\StoreDirectVoteRequest;
use App\Requests\StoreDuelVoteRequest;
use App\Support\VoteRequestPayloadResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VoteController extends Controller
{
    public function store(
        StoreDuelVoteRequest $request,
        StoreDuelVoteAction $storeDuelVoteAction,
    ) {
        $validated = $request->validated();

        $result = $storeDuelVoteAction->execute($validated, $request);

        return response()->json($result['body'], $result['status']);
    }

    public function storeDirect(
        StoreDirectVoteRequest $request,
        StoreDirectVoteAction $storeDirectVoteAction,
    ) {
        $validated = $request->validated();

        $result = $storeDirectVoteAction->execute(
            $validated,
            (int) auth()->id(),
        );

        if (($result['status'] ?? 500) >= 400) {
            Log::warning('direct_vote.submit_failed', [
                'user_id' => auth()->id(),
                'player_id' => $payload['player_id'] ?? null,
                'attribute_key' => $payload['attribute_key'] ?? null,
                'value' => $payload['value'] ?? null,
                'status' => $result['status'] ?? null,
                'body' => $result['body'] ?? null,
            ]);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function submitScoutReport(
        Request $request,
        VoteRequestPayloadResolver $voteRequestPayloadResolver,
        SubmitScoutReportAction $submitScoutReportAction,
    ) {
        $payload = $voteRequestPayloadResolver->resolve($request);

        $v = Validator::make($payload, [
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'votes' => ['array', 'max:6'],
            'votes.*.attribute_key' => ['required', 'string', 'exists:attributes,key'],
            'votes.*.value' => ['required', 'integer', 'min:1', 'max:99'],
            'skipped_attribute_ids' => ['array', 'max:6'],
            'skipped_attribute_ids.*' => ['integer', 'exists:attributes,id'],
        ]);

        $v->after(function ($validator) use ($payload) {
            $votes = $payload['votes'] ?? [];
            $skips = $payload['skipped_attribute_ids'] ?? [];

            if (count($votes) === 0 && count($skips) === 0) {
                $validator->errors()->add('payload', 'At least one vote or one skip is required.');
                return;
            }

            $voteKeys = collect($votes)
                ->pluck('attribute_key')
                ->filter()
                ->values();

            if ($voteKeys->count() !== $voteKeys->unique()->count()) {
                $validator->errors()->add('votes', 'Duplicate attribute_key in votes payload.');
            }

            $skipIds = collect($skips)
                ->map(fn ($id) => (int) $id)
                ->values();

            if ($skipIds->count() !== $skipIds->unique()->count()) {
                $validator->errors()->add('skipped_attribute_ids', 'Duplicate attribute_id in skipped payload.');
            }

            $touchedCount = $voteKeys->count() + $skipIds->unique()->count();

            if ($touchedCount > 6) {
                $validator->errors()->add('payload', 'Scout report submit can contain at most 6 touched attributes.');
            }

            if ($voteKeys->isNotEmpty() && $skipIds->isNotEmpty()) {
                $overlapExists = \App\Models\Attribute::query()
                    ->whereIn('key', $voteKeys->all())
                    ->whereIn('id', $skipIds->all())
                    ->exists();

                if ($overlapExists) {
                    $validator->errors()->add('payload', 'The same attribute cannot be voted and skipped in one submit.');
                }
            }
        });

        if ($v->fails()) {
            Log::warning('scout_report.validation_failed', [
                'user_id' => auth()->id(),
                'player_id' => $payload['player_id'] ?? null,
                'votes_count' => count($payload['votes'] ?? []),
                'skips_count' => count($payload['skipped_attribute_ids'] ?? []),
                'errors' => $v->errors()->toArray(),
            ]);

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $v->errors(),
            ], 422);
        }

        $result = $submitScoutReportAction->execute(
            (int) auth()->id(),
            $v->validated(),
        );

        if (($result['status'] ?? 500) >= 400) {
            Log::warning('scout_report.submit_failed', [
                'user_id' => auth()->id(),
                'player_id' => $payload['player_id'] ?? null,
                'votes_count' => count($payload['votes'] ?? []),
                'skips_count' => count($payload['skipped_attribute_ids'] ?? []),
                'status' => $result['status'] ?? null,
                'body' => $result['body'] ?? null,
            ]);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function scoutReportAttributes(
        Request $request,
        \App\Models\Player $player,
        GetScoutReportAttributesAction $getScoutReportAttributesAction,
    ) {
        $result = $getScoutReportAttributesAction->execute(
            (int) auth()->id(),
            (int) $player->id,
        );

        return response()->json($result['body'], $result['status']);
    }
}
