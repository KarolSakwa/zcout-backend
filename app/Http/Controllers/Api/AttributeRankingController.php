<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttributeRankingRequest;
use App\Models\Attribute;
use App\Models\PlayerAttributeRating;

class AttributeRankingController extends Controller
{
    public function index(AttributeRankingRequest $request, string $key)
    {
        $attribute = Attribute::query()->where('key', $key)->firstOrFail();

        $position = $request->validated('position');
        $minVotes = (int) ($request->validated('min_votes') ?? 0);
        $limit    = (int) ($request->validated('limit') ?? 50);
        $cursor   = $request->validated('cursor');

        $paginator = PlayerAttributeRating::query()
            ->with('player:id,name,slug,country,club,position')
            ->where('attribute_id', $attribute->id)
            ->where('votes_count', '>=', $minVotes)
            ->when($position, function ($q) use ($position) {
                $q->whereHas('player', fn ($p) => $p->where('position', $position));
            })
            ->orderByDesc('rating')
            ->orderByDesc('votes_count')
            ->orderBy('player_id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        $rows = collect($paginator->items())->map(fn ($par) => [
            'player' => [
                'id'       => $par->player->id,
                'name' => (string) $par->player->effective_name,
                'slug'     => $par->player->slug,
                'country'  => $par->player->country,
                'club'     => $par->player->club,
                'position' => $par->player->position,
            ],
            'rating'      => $par->rating,
            'votes_count' => $par->votes_count,
        ])->values();

        return response()->json([
            'attribute' => [
                'id'  => $attribute->id,
                'key' => $attribute->key,
                'name'=> $attribute->name ?? null,
            ],
            'meta' => [
                'limit'       => $limit,
                'min_votes'   => $minVotes,
                'position'    => $position,
                'next_cursor' => optional($paginator->nextCursor())->encode(),
                'prev_cursor' => optional($paginator->previousCursor())->encode(),
            ],
            'ranking' => $rows,
        ]);
    }
}
