<?php

namespace App\Services\Ranking;

class RankingResultBuilder
{
    public function normalizeSort(string $sort): string
    {
        $sort = trim(mb_strtolower($sort));

        return in_array($sort, ['rank', 'player', 'club', 'pos', 'rating', 'trend'], true)
            ? $sort
            : 'rank';
    }

    public function normalizeDir(string $dir): string
    {
        $dir = trim(mb_strtolower($dir));

        return $dir === 'desc' ? 'desc' : 'asc';
    }

    public function rankAndSortItems(array $items, string $sort, string $dir, int $limit, int $page, string $search = ''): array
    {
        usort($items, function ($a, $b) {
            $c = $b['rating'] <=> $a['rating'];
            if ($c !== 0) {
                return $c;
            }

            $c = $b['confidence'] <=> $a['confidence'];
            if ($c !== 0) {
                return $c;
            }

            return $a['player']['id'] <=> $b['player']['id'];
        });

        foreach ($items as $i => $it) {
            $items[$i]['rank'] = $i + 1;
        }

        if ($search !== '') {
            $needle = $this->normalizeSearchText($search);

            $items = array_values(array_filter($items, function (array $item) use ($needle) {
                $name = $this->normalizeSearchText((string) ($item['player']['name'] ?? ''));

                return str_contains($name, $needle);
            }));
        }

        if ($sort !== 'rank') {
            usort($items, function ($a, $b) use ($sort, $dir) {
                $result = match ($sort) {
                    'player' => $this->compareText($a['player']['name'], $b['player']['name']),
                    'club' => $this->compareText($a['player']['club']['name'] ?? '', $b['player']['club']['name'] ?? ''),
                    'pos' => $this->compareText($a['pos'], $b['pos']),
                    'rating' => $a['rating'] <=> $b['rating'],
                    'trend' => $this->compareNullableNumber($a['trend_7d'], $b['trend_7d'], $dir),
                    default => $a['rank'] <=> $b['rank'],
                };

                if ($result === 0) {
                    $result = $a['rank'] <=> $b['rank'];
                }

                if ($sort === 'trend') {
                    return $result;
                }

                return $dir === 'desc' ? -$result : $result;
            });
        } elseif ($dir === 'desc') {
            $items = array_reverse($items);
        }

        $totalItems = count($items);
        $totalPages = max(1, (int) ceil($totalItems / $limit));
        $safePage = min(max(1, $page), $totalPages);
        $offset = ($safePage - 1) * $limit;
        $pagedItems = array_slice(array_values($items), $offset, $limit);

        return [
            'items' => $pagedItems,
            'total' => $totalItems,
            'total_pages' => $totalPages,
            'page' => $safePage,
        ];
    }

    private function compareText(?string $a, ?string $b): int
    {
        $a = trim((string) $a);
        $b = trim((string) $b);

        if (class_exists(\Collator::class)) {
            $collator = new \Collator('pl_PL');
            $result = $collator->compare($a, $b);

            if ($result !== false) {
                return $result;
            }
        }

        return strcmp(
            mb_strtolower($a),
            mb_strtolower($b)
        );
    }

    private function compareNullableNumber($a, $b, string $dir = 'asc'): int
    {
        $aNull = $a === null;
        $bNull = $b === null;

        if ($aNull && $bNull) {
            return 0;
        }

        if ($aNull) {
            return 1;
        }

        if ($bNull) {
            return -1;
        }

        $result = (float) $a <=> (float) $b;

        return $dir === 'desc' ? -$result : $result;
    }

    private function normalizeSearchText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
