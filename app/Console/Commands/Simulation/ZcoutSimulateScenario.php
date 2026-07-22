<?php

namespace App\Console\Commands\Simulation;

use App\Http\Controllers\Api\DuelController;
use App\Http\Controllers\Api\VoteController;
use App\Models\Attribute;
use App\Models\PlayerAttributeRating;
use App\Services\RatingService;
use App\Support\Seed;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZcoutSimulateScenario extends Command
{
    protected $signature = 'zcout:simulate-scenario
        {--dau=100}
        {--experts=10}
        {--mids=50}
        {--noobs=40}
        {--avg-duels=5}
        {--activity=heavy}
        {--seed=123}
        {--reset=1}
        {--init=seeds}
        {--attr=}
        {--own-share=0.0}';

    protected $description = 'Simulate duel voting traffic using real matchmaking and rating updates';

    private float $ownShare = 0.0;

    public function handle(): int
    {
        $seed = (int) $this->option('seed');
        mt_srand($seed);

        $dau = (int) $this->option('dau');
        $experts = (int) $this->option('experts');
        $mids = (int) $this->option('mids');
        $noobs = (int) $this->option('noobs');

        if (($experts + $mids + $noobs) !== $dau) {
            $this->error("experts+mids+noobs must equal dau (given: {$experts}+{$mids}+{$noobs} != {$dau})");
            return self::FAILURE;
        }

        $avgDuels = (int) $this->option('avg-duels');
        if ($avgDuels < 1) $avgDuels = 1;

        $activity = (string) $this->option('activity');
        $reset = (string) $this->option('reset') !== '0';
        $init = (string) $this->option('init');

        $forcedAttr = $this->option('attr');
        $forcedAttr = is_string($forcedAttr) && trim($forcedAttr) !== '' ? trim($forcedAttr) : null;

        $ownShare = (float) $this->option('own-share');
        if ($ownShare < 0) $ownShare = 0.0;
        if ($ownShare > 1) $ownShare = 1.0;
        $this->ownShare = $ownShare;

        $attrs = Attribute::query()->select(['id', 'key'])->orderBy('id')->get();
        if ($attrs->isEmpty()) {
            $this->error('No attributes found.');
            return self::FAILURE;
        }

        $attrKeys = $attrs->pluck('key')->values()->all();
        $attrIdByKey = $attrs->pluck('id', 'key')->all();

        if ($forcedAttr !== null && !isset($attrIdByKey[$forcedAttr])) {
            $this->error("Unknown attribute key: {$forcedAttr}");
            return self::FAILURE;
        }

        $repRows = DB::table('player_reputation_stats')
            ->select(['player_id', 'player_rep'])
            ->get();

        $repByPlayer = [];
        $maxRep = 0.0;

        foreach ($repRows as $r) {
            $pid = (int) $r->player_id;
            $rep = (float) ($r->player_rep ?? 0);
            $repByPlayer[$pid] = $rep;
            if ($rep > $maxRep) $maxRep = $rep;
        }

        if ($maxRep <= 0) $maxRep = 1.0;

        if ($reset) {
            DB::transaction(function () {
                DB::table('votes')->delete();
                DB::table('duels')->delete();
                DB::table('player_attribute_ratings')->delete();
            });
            $this->info('Reset done: votes, duels, player_attribute_ratings cleared.');
        }

        if ($init === 'seeds') {
            $this->seedAllRatings($attrs);
            $this->info('Init done: ratings set to seeds for all players x attributes.');
        } elseif ($init === 'flat') {
            $this->flatAllRatings($attrs, 50.0);
            $this->info('Init done: ratings set to flat=50 for all players x attributes.');
        } else {
            $this->info('Init skipped: ratings will be created lazily on first vote.');
        }

        $agents = $this->buildAgents($experts, $mids, $noobs, $avgDuels, $activity);
        $totalPlanned = array_sum(array_map(fn ($a) => $a['duels'], $agents));
        $ownCount = (int) array_sum(array_map(fn ($a) => $a['own'] ? 1 : 0, $agents));

        $this->info("Agents={$dau} plannedDuels={$totalPlanned} seed={$seed} activity={$activity} init={$init} ownShare={$this->ownShare} ownAgents={$ownCount}");

        $duelController = app(DuelController::class);
        $voteController = app(VoteController::class);
        $ratingService = app(RatingService::class);

        $votesDone = 0;

        foreach ($agents as $agent) {
            $tier = $agent['tier'];
            $voterHash = $agent['voter_hash'];
            $duelsToDo = $agent['duels'];
            $ownOpinion = (bool) $agent['own'];

            for ($i = 0; $i < $duelsToDo; $i++) {
                $attrKey = $forcedAttr ?? $attrKeys[$this->randInt(0, count($attrKeys) - 1)];
                $payload = $this->fetchNextDuelPayload($duelController, $attrKey);

                if (!$payload || empty($payload['attribute']['key']) || empty($payload['players']) || count($payload['players']) < 2) {
                    continue;
                }

                $aId = (int) $payload['players'][0]['id'];
                $bId = (int) $payload['players'][1]['id'];

                if ($aId === $bId) {
                    continue;
                }

                $attrId = (int) ($payload['attribute']['id'] ?? ($attrIdByKey[$attrKey] ?? 0));
                if ($attrId <= 0) {
                    continue;
                }

                $posA = strtoupper((string) ($payload['players'][0]['position'] ?? ''));
                $posB = strtoupper((string) ($payload['players'][1]['position'] ?? ''));

                $ra = $this->currentRating($aId, $attrId, $posA, $attrKey);
                $rb = $this->currentRating($bId, $attrId, $posB, $attrKey);

                $repA = (float) ($repByPlayer[$aId] ?? 0.0);
                $repB = (float) ($repByPlayer[$bId] ?? 0.0);

                $winnerId = $this->pickWinner($tier, $ownOpinion, $aId, $bId, $ra, $rb, $repA, $repB, $maxRep);

                $this->submitVote($voteController, $ratingService, $voterHash, $attrKey, $aId, $bId, $winnerId);

                $votesDone++;
                if ($votesDone % 2000 === 0) {
                    $this->line("votesDone={$votesDone}");
                }
            }
        }

        $votesCount = (int) DB::table('votes')->count();
        $duelsCount = (int) DB::table('duels')->count();
        $ratedCount = (int) DB::table('player_attribute_ratings')->count();

        $this->info("Done. votes={$votesCount} duels={$duelsCount} ratingsRows={$ratedCount}");

        return self::SUCCESS;
    }

    private function fetchNextDuelPayload(DuelController $duelController, string $attrKey): ?array
    {
        $req = Request::create('/api/duels/next', 'GET', [
            'attribute' => $attrKey,
        ]);

        app()->instance('request', $req);

        $resp = $duelController->next();
        if (!method_exists($resp, 'getData')) return null;

        $data = $resp->getData(true);
        return is_array($data) ? $data : null;
    }

    private function submitVote(VoteController $voteController, RatingService $ratingService, string $voterHash, string $attrKey, int $aId, int $bId, int $winnerId): void
    {
        $body = json_encode([
            'attribute_key' => $attrKey,
            'player_a_id' => $aId,
            'player_b_id' => $bId,
            'winner_id' => $winnerId,
        ]);

        $req = Request::create('/api/votes', 'POST', [], [], [], [], $body);
        $req->headers->set('Content-Type', 'application/json');
        $req->headers->set('X-Voter-Hash', $voterHash);

        app()->instance('request', $req);

        $voteController->store($req, $ratingService);
    }

    private function currentRating(int $playerId, int $attributeId, string $posShort, string $attrKey): float
    {
        $row = PlayerAttributeRating::query()
            ->where('player_id', $playerId)
            ->where('attribute_id', $attributeId)
            ->first();

        if ($row) {
            return (float) $row->rating;
        }

        $pos = $posShort !== '' ? $posShort : 'CM';
        $seed = Seed::for($pos, $attrKey);
        return is_numeric($seed) ? (float) $seed : 50.0;
    }

    private function pickWinner(string $tier, bool $ownOpinion, int $aId, int $bId, float $ra, float $rb, float $repA, float $repB, float $maxRep): int
    {
        $repNA = $repA / $maxRep;
        $repNB = $repB / $maxRep;

        if ($repNA < 0) $repNA = 0;
        if ($repNB < 0) $repNB = 0;
        if ($repNA > 1) $repNA = 1;
        if ($repNB > 1) $repNB = 1;

        if ($ownOpinion) {
            $score = 2.2 * ($repNA - $repNB) + ($this->randNormal() * 0.55);
            $pA = 1.0 / (1.0 + exp(-$score));
            return ($this->randFloat() < $pA) ? $aId : $bId;
        }

        $cfg = $this->tierCfg($tier);

        $k = (float) $cfg['k'];
        $b = (float) $cfg['b'];
        $u = (float) $cfg['u'];

        $ratingScale = 12.0;

        $score = $k * (($ra - $rb) / $ratingScale) + $b * ($repNA - $repNB);

        $pA = 1.0 / (1.0 + exp(-$score));

        $pickA = $this->randFloat() < $pA;

        if ($this->randFloat() < $u) {
            $pickA = !$pickA;
        }

        return $pickA ? $aId : $bId;
    }

    private function tierCfg(string $tier): array
    {
        if ($tier === 'expert') return ['k' => 1.2, 'b' => 0.10, 'u' => 0.06];
        if ($tier === 'mid') return ['k' => 0.7, 'b' => 0.30, 'u' => 0.12];
        return ['k' => 0.25, 'b' => 0.60, 'u' => 0.22];
    }

    private function buildAgents(int $experts, int $mids, int $noobs, int $avgDuels, string $activity): array
    {
        $out = [];
        $idx = 1;

        for ($i = 0; $i < $experts; $i++) {
            $d = $this->duelsForAgent($avgDuels, $activity);
            $out[] = ['tier' => 'expert', 'voter_hash' => "SIM:expert:{$idx}", 'duels' => $d, 'own' => ($this->randFloat() < $this->ownShare)];
            $idx++;
        }

        for ($i = 0; $i < $mids; $i++) {
            $d = $this->duelsForAgent($avgDuels, $activity);
            $out[] = ['tier' => 'mid', 'voter_hash' => "SIM:mid:{$idx}", 'duels' => $d, 'own' => ($this->randFloat() < $this->ownShare)];
            $idx++;
        }

        for ($i = 0; $i < $noobs; $i++) {
            $d = $this->duelsForAgent($avgDuels, $activity);
            $out[] = ['tier' => 'noob', 'voter_hash' => "SIM:noob:{$idx}", 'duels' => $d, 'own' => ($this->randFloat() < $this->ownShare)];
            $idx++;
        }

        return $out;
    }

    private function duelsForAgent(int $avg, string $activity): int
    {
        if ($activity === 'flat') return $avg;

        if ($activity === 'poisson') {
            $k = $this->poisson($avg);
            return max(1, $k);
        }

        $r = $this->randFloat();

        if ($r < 0.60) {
            $n = (int) round($avg * 0.5) + $this->randInt(0, 2);
            return max(1, $n);
        }

        if ($r < 0.90) {
            $n = (int) round($avg * 1.0) + $this->randInt(-1, 2);
            return max(1, $n);
        }

        $n = (int) round($avg * 2.5) + $this->randInt(-2, 5);
        return max(1, $n);
    }

    private function poisson(float $lambda): int
    {
        $L = exp(-$lambda);
        $k = 0;
        $p = 1.0;

        do {
            $k++;
            $p *= $this->randFloat();
        } while ($p > $L);

        return $k - 1;
    }

    private function seedAllRatings($attrs): void
    {
        $players = DB::table('players as p')
            ->join('positions as pos', 'pos.id', '=', 'p.position_id')
            ->select(['p.id as player_id', DB::raw('UPPER(pos.short_label) as pos_short')])
            ->orderBy('p.id')
            ->get();

        $batch = [];
        foreach ($players as $p) {
            $pid = (int) $p->player_id;
            $pos = (string) ($p->pos_short ?? 'CM');

            foreach ($attrs as $a) {
                $key = (string) $a->key;
                $seed = Seed::for($pos, $key);
                $val = is_numeric($seed) ? (float) $seed : 50.0;

                $batch[] = [
                    'player_id' => $pid,
                    'attribute_id' => (int) $a->id,
                    'rating' => number_format($val, 3, '.', ''),
                    'votes_count' => 0,
                    'rating_weight_sum' => 0,
                    'confidence_weight_sum' => 0,
                    'confidence' => 0,
                    'last_vote_at' => null,
                ];

                if (count($batch) >= 1000) {
                    DB::table('player_attribute_ratings')->insert($batch);
                    $batch = [];
                }
            }
        }

        if ($batch) {
            DB::table('player_attribute_ratings')->insert($batch);
        }
    }

    private function flatAllRatings($attrs, float $value): void
    {
        $players = DB::table('players as p')
            ->whereNotNull('p.position_id')
            ->select(['p.id as player_id'])
            ->orderBy('p.id')
            ->get();

        $batch = [];
        foreach ($players as $p) {
            $pid = (int) $p->player_id;

            foreach ($attrs as $a) {
                $batch[] = [
                    'player_id' => $pid,
                    'attribute_id' => (int) $a->id,
                    'rating' => number_format($value, 3, '.', ''),
                    'votes_count' => 0,
                    'rating_weight_sum' => 0,
                    'confidence_weight_sum' => 0,
                    'confidence' => 0,
                    'last_vote_at' => null,
                ];

                if (count($batch) >= 1000) {
                    DB::table('player_attribute_ratings')->insert($batch);
                    $batch = [];
                }
            }
        }

        if ($batch) {
            DB::table('player_attribute_ratings')->insert($batch);
        }
    }

    private function randFloat(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function randInt(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    private function randNormal(): float
    {
        $u1 = $this->randFloat();
        $u2 = $this->randFloat();
        if ($u1 < 1e-12) $u1 = 1e-12;
        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
