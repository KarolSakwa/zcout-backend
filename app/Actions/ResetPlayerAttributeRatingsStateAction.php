<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

final class ResetPlayerAttributeRatingsStateAction
{
    public function execute(): int
    {
        return DB::table('player_attribute_ratings')->delete();
    }
}
