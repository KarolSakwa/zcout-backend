<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\Position;
use App\Support\Seed;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function meta()
    {
        $attributes = Attribute::query()
            ->select('key')
            ->orderBy('key')
            ->get()
            ->map(function ($a) {
                $key = (string) $a->key;
                return [
                    'value' => $key,
                    'label' => strtoupper(str_replace('_', ' ', $key)),
                ];
            })
            ->values();

        $positions = Position::query()
            ->select('short_label')
            ->orderBy('short_label')
            ->get()
            ->map(function ($p) {
                $v = strtoupper((string) $p->short_label);
                return [
                    'value' => $v,
                    'label' => $v,
                ];
            })
            ->values();

        $positions->prepend(['value' => 'ALL', 'label' => 'ALL POSITIONS']);

        return response()->json([
            'attributes' => $attributes,
            'positions' => $positions,
            'limits' => [
                ['value' => '25', 'label' => 'TOP 25'],
                ['value' => '50', 'label' => 'TOP 50'],
                ['value' => '100', 'label' => 'TOP 100'],
            ],
        ]);
    }

    public function attribute(string $attributeKey, Request $request)
    {
        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $attributeKey)
            ->first();

        if (!$attribute) {
            return response()->json(['message' => 'Attribute not found.'], 404);
        }

        $limit = (int) $request->query('limit', 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;

        $position = strtoupper((string) $request->query('position', ''));
        if ($position === 'ALL') $position = '';

        $playersQuery = Player::query()
            ->select('id', 'name', 'club', 'position_id')
            ->with(['positionRef:id,short_label']);

        if ($position !== '') {
            $playersQuery->whereHas('positionRef', function ($q) use ($position) {
                $q->where('short_label', $position);
            });
        }

        $players = $playersQuery->get();
        $playerIds = $players->pluck('id')->all();

        $ratingRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', $playerIds)
            ->get()
            ->keyBy('player_id');

        $items = [];
        foreach ($players as $p) {
            $pos = strtoupper((string) ($p->positionRef?->short_label ?? ''));
            $row = $ratingRows[$p->id] ?? null;

            $rating = (float) ($row?->rating ?? Seed::for($pos, $attribute->key));
            $confidence = (float) ($row?->confidence ?? 0);
            $lastVoteAt = $row?->last_vote_at;

            $items[] = [
                'player' => [
                    'id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'club' => (string) $p->club,
                ],
                'pos' => $pos,
                'rating' => (float) round($rating, 3),
                'confidence' => (float) round($confidence, 3),
                'last_vote_at' => $lastVoteAt,
                'trend_7d' => null,
            ];
        }

        usort($items, function ($a, $b) {
            $c = $b['rating'] <=> $a['rating'];
            if ($c !== 0) return $c;
            $c = $b['confidence'] <=> $a['confidence'];
            if ($c !== 0) return $c;
            return $a['player']['id'] <=> $b['player']['id'];
        });

        $items = array_slice(array_values($items), 0, $limit);

        foreach ($items as $i => $it) {
            $items[$i]['rank'] = $i + 1;
        }

        return response()->json([
            'attribute' => ['id' => (int) $attribute->id, 'key' => (string) $attribute->key],
            'filters' => [
                'position' => $position === '' ? 'ALL' : $position,
                'limit' => $limit,
            ],
            'total' => count($players),
            'items' => $items,
        ]);
    }
}
