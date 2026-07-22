<?php

namespace App\Http\Controllers\Api;

use App\Actions\Rankings\BuildRankingAttributeAction;
use App\Actions\Rankings\BuildRankingMetaAction;
use App\Http\Controllers\Controller;
use App\Services\Ranking\RankingResultBuilder;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function __construct(
        private BuildRankingMetaAction $buildRankingMetaAction,
        private BuildRankingAttributeAction $buildRankingAttributeAction,
        private RankingResultBuilder $rankingItemSorter,
    ) {}

    public function meta()
    {
        return response()->json($this->buildRankingMetaAction->execute());
    }

    public function attribute(string $attributeKey, Request $request)
    {
        $limit = (int) $request->query('limit', 25);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $page = max(1, (int) $request->query('page', 1));

        $position = strtoupper((string) $request->query('position', ''));
        if ($position === 'ALL') {
            $position = '';
        }

        $search = trim((string) $request->query('search', ''));
        $sort = $this->rankingItemSorter->normalizeSort((string) $request->query('sort', 'rank'));
        $dir = $this->rankingItemSorter->normalizeDir((string) $request->query('dir', 'asc'));

        $result = $this->buildRankingAttributeAction->execute(
            $attributeKey,
            $position,
            $limit,
            $page,
            $sort,
            $dir,
            $search,
        );

        return response()->json($result['body'], $result['status']);
    }
}
