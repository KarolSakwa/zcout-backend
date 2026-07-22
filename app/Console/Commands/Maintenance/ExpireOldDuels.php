<?php

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireOldDuels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:expire-old-duels';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();
        $ttlMinutes = (int) $this->option('ttl');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = $now->copy()->subMinutes($ttlMinutes);

        $ids = DB::table('duels')
            ->where('status', 'pending')
            ->where(function ($q) use ($now, $cutoff) {
                $q->where(function ($q2) use ($now) {
                    $q2->whereNotNull('expires_at')
                        ->where('expires_at', '<', $now);
                })->orWhere(function ($q2) use ($cutoff) {
                    $q2->whereNull('expires_at')
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            $this->info('No pending duels to expire.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Would expire: ' . count($ids) . ' duels');
            return self::SUCCESS;
        }

        $updated = DB::table('duels')
            ->whereIn('id', $ids)
            ->update([
                'status' => 'expired',
                'completed_at' => $now,
            ]);

        $this->info("Expired: {$updated} duels");
        return self::SUCCESS;
    }

}
