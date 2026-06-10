<?php

namespace App\Console\Commands;

use App\Models\PlayerAttributeRating;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class RebuildAttributeRankingProjectionCommand extends Command
{
    protected $signature = 'app:rebuild-attribute-ranking-projection-command';

    protected $description = 'Rebuild attribute ranking projections';

    public function handle(): int
    {
        $keys = Redis::keys('ranking:*');

        foreach ($keys as $key) {
            if ($key === 'laravel_database_ranking:overall') {
                continue;
            }

            Redis::del(str_replace('laravel_database_', '', $key));
        }

        PlayerAttributeRating::query()
            ->select([
                'player_id',
                'attribute_id',
                'rating',
            ])
            ->with('attribute:id,key')
            ->get()
            ->each(function (PlayerAttributeRating $row) {
                Redis::zadd(
                    'ranking:' . $row->attribute->key,
                    (float) $row->rating,
                    (string) $row->player_id,
                );
            });

        $this->info('Attribute ranking projections rebuilt');

        return self::SUCCESS;
    }
}
