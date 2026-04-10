<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Support\Seed;
use Illuminate\Support\Facades\Log;

class RatingService
{
    private function posCode(Player $p): string
    {
        $code = $p->positionRef?->short_label
            ?? $p->positionRef?->key
            ?? $p->positionRef?->label
            ?? 'ST';

        return strtoupper((string) $code);
    }

    public function applyVote(int $winnerId, int $loserId, int $attributeId, float $ratingWeight = 1.0, float $confidenceWeight = 1.0): array
    {
        $attr = Attribute::select('id', 'key')->findOrFail($attributeId);

        $winnerPlayer = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label,key,label,group'])
            ->whereKey($winnerId)
            ->firstOrFail();

        $loserPlayer = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label,key,label,group'])
            ->whereKey($loserId)
            ->firstOrFail();

        $winnerPos = strtoupper((string) ($winnerPlayer->positionRef?->short_label ?? ''));
        $loserPos  = strtoupper((string) ($loserPlayer->positionRef?->short_label ?? ''));

        $w = PlayerAttributeRating::firstOrCreate(
            ['player_id' => $winnerId, 'attribute_id' => $attributeId],
            [
                'rating' => Seed::for($winnerPos, $attr->key),
                'rating_weight_sum' => 0,
                'confidence_weight_sum' => 0,
                'confidence' => 0,
                'votes_count' => 0,
                'last_vote_at' => null,
            ]
        );

        $l = PlayerAttributeRating::firstOrCreate(
            ['player_id' => $loserId, 'attribute_id' => $attributeId],
            [
                'rating' => Seed::for($loserPos, $attr->key),
                'rating_weight_sum' => 0,
                'confidence_weight_sum' => 0,
                'confidence' => 0,
                'votes_count' => 0,
                'last_vote_at' => null,
            ]
        );

        $beforeW = (float) $w->rating;
        $beforeL = (float) $l->rating;

        $n = ((int) $w->votes_count + (int) $l->votes_count) + 1;

        $ratingWeight = max(0.0, (float) $ratingWeight);
        $confidenceWeight = max(0.0, (float) $confidenceWeight);

        $updated = $this->updateRatingsFromVote(
            $beforeW,
            $beforeL,
            $winnerPos,
            $loserPos,
            1,
            $n,
            null,
            $ratingWeight
        );

        $afterW = (float) ($updated['ratingA'] ?? $updated[0] ?? $beforeW);
        $afterL = (float) ($updated['ratingB'] ?? $updated[1] ?? $beforeL);

        $now = now();

        $w->rating = $afterW;
        $w->votes_count = ((int) $w->votes_count) + 1;
        $w->rating_weight_sum = ((float) ($w->rating_weight_sum ?? 0)) + $ratingWeight;
        $w->confidence_weight_sum = ((float) ($w->confidence_weight_sum ?? 0)) + $confidenceWeight;
        $w->confidence = min(100.0, round((float) $w->confidence_weight_sum, 2));
        $w->last_vote_at = $now;
        $w->save();

        $l->rating = $afterL;
        $l->votes_count = ((int) $l->votes_count) + 1;
        $l->rating_weight_sum = ((float) ($l->rating_weight_sum ?? 0)) + $ratingWeight;
        $l->confidence_weight_sum = ((float) ($l->confidence_weight_sum ?? 0)) + $confidenceWeight;
        $l->confidence = min(100.0, round((float) $l->confidence_weight_sum, 2));
        $l->last_vote_at = $now;
        $l->save();

        Log::info('rating.applyVote.timing', [
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'attribute_id' => $attributeId,
            'n' => $n,
            'ratingWeight' => $ratingWeight,
            'confidenceWeight' => $confidenceWeight,
            'kEff' => isset($updated['kEff']) ? round((float) $updated['kEff'], 6) : null,
            'expectedA' => isset($updated['expectedA']) ? round((float) $updated['expectedA'], 6) : null,
        ]);

        return [
            'winner_seed_pos' => $winnerPos,
            'loser_seed_pos'  => $loserPos,
        ];
    }

    public function updateRatingsFromVote(
        float $ratingA,
        float $ratingB,
        string $posA,
        string $posB,
        int $scoreA,
        int $n,
        ?float $pCrowdA = null,
        float $ratingWeight = 1.0
    ): array {
        $Sexp = 14.0;

        $K0   = 3.0;
        $n0   = 5.0;
        $kMin = 0.02;
        $kMax = 1.50;

        $nn = max(1, $n);
        $baseKEff = $K0 / sqrt($nn + $n0);
        $baseKEff = $this->clamp($baseKEff, $kMin, $kMax);

        $ratingWeight = max(0.0, (float) $ratingWeight);
        $kEff = $this->clamp($baseKEff * $ratingWeight, 0.0, $kMax);

        $E = $this->expectedProb($ratingA, $ratingB, $Sexp);

        $delta = $kEff * ((float) $scoreA - $E);

        $Dold = $ratingA - $ratingB;
        $Dnew = $Dold + (2.0 * $delta);
        $mean = 0.5 * ($ratingA + $ratingB);

        $newA = $this->clamp($mean + 0.5 * $Dnew, 0.0, 99.0);
        $newB = $this->clamp($mean - 0.5 * $Dnew, 0.0, 99.0);

        $gapTarget = null;
        if ($pCrowdA !== null) {
            $gapTarget = $Sexp * $this->logit($pCrowdA);
        }

        return [
            0 => $newA,
            1 => $newB,
            2 => (2.0 * $delta),
            'ratingA' => $newA,
            'ratingB' => $newB,
            'deltaChange' => (2.0 * $delta),
            'kEff' => $kEff,
            'expectedA' => $E,
            'gapBefore' => $Dold,
            'gapAfter' => $Dnew,
            'gapTarget' => $gapTarget,
        ];
    }

    private function clamp(float $x, float $L, float $U): float
    {
        return min($U, max($L, $x));
    }

    private function sigmoid(float $z): float
    {
        return 1.0 / (1.0 + exp(-$z));
    }

    private function logit(float $p): float
    {
        $eps = 1e-9;
        $p = max($eps, min(1.0 - $eps, $p));
        return log($p / (1.0 - $p));
    }

    private function expectedProb(float $rA, float $rB, float $S_exp = 14.0): float
    {
        return $this->sigmoid(($rA - $rB) / $S_exp);
    }

    private function posRange(string $pos): array
    {
        $map = [
            'LW' => [50.0, 99.0], 'RW' => [50.0, 99.0], 'ST' => [40.0, 95.0],
            'CM' => [35.0, 92.0], 'CB' => [10.0, 80.0], 'GK' => [5.0, 70.0],
        ];

        return $map[$pos] ?? [0.0, 99.0];
    }

    private function kappaGap(float $gap, float $rK = 8.0, float $betaK = 2.0): float
    {
        $x = pow($gap / $rK, $betaK);
        return $x / (1.0 + $x);
    }

    private function surpriseStrength(float $rawLogit, float $expectedLogit, float $L0 = 2.0): float
    {
        return min(1.0, abs($rawLogit - $expectedLogit) / $L0);
    }

    private function oppQualityMultiplier(float $rOpp, float $q0 = 60.0, float $rQ = 10.0, float $betaQ = 1.5): float
    {
        $d = max(0.0, $q0 - $rOpp);
        if ($d <= 0.0) return 1.0;

        $x = pow($d / $rQ, $betaQ);
        return 1.0 / (1.0 + $x);
    }

    private function fSqrt(int $n, float $C = 5.0): float
    {
        $r = sqrt(max(1, $n));
        return $r / ($r + $C);
    }

    private function fBlocks(int $n, float $a = 0.08, float $floor = 0.70): float
    {
        $n = max(10, $n);
        $val = 1.0 - $a * max(1.0, log10($n));
        return max($floor, $val);
    }

    public function updateRatingsFromDuel(
        float $ratingA,
        float $ratingB,
        string $posA,
        string $posB,
        float $pA,
        int $n
    ): array {
        $S0 = 45.0;
        $r0 = 10.0;
        $beta = 1.2;
        $capMin = 6.0;
        $capMax = 16.0;
        $capAlpha = 1.2;
        $L0Surprise = 2.0;

        $C = 5.0;
        $aBlocks = 0.08;
        $floorBlocks = 0.70;

        $Sexp = 14.0;
        $expectKBase = 1.0;
        $rK = 8.0;
        $betaK = 2.0;

        $q0 = 60.0;
        $rQ = 10.0;
        $betaQ = 1.5;

        $eps = 1e-9;
        $pA_c = max($eps, min(1.0 - $eps, $pA));
        $rawLog = log($pA_c / (1.0 - $pA_c));

        $E = $this->expectedProb($ratingA, $ratingB, $Sexp);
        $E_c = max($eps, min(1.0 - $eps, $E));
        $expLog = log($E_c / (1.0 - $E_c));

        $gap = abs($ratingA - $ratingB);
        $kappa = $this->kappaGap($gap, $rK, $betaK) * $expectKBase;
        $logEff = $rawLog - $kappa * $expLog;

        $wScale = 1.0 / (1.0 + pow($gap / $r0, $beta));
        $Seff = $S0 * $wScale;
        $capBase = $capMin + ($capMax - $capMin) * $wScale;

        $rOpp = ($pA >= 0.5) ? $ratingB : $ratingA;
        $mOpp = $this->oppQualityMultiplier($rOpp, $q0, $rQ, $betaQ);
        $Seff *= $mOpp;
        $capBase *= $mOpp;

        $s = $this->surpriseStrength($rawLog, $expLog, $L0Surprise);
        $cap = $capBase * (1.0 + $capAlpha * $s);

        $deltaChange = $Seff * $logEff * $this->fSqrt($n, $C) * $this->fBlocks($n, $aBlocks, $floorBlocks);

        if ($deltaChange > $cap) $deltaChange = $cap;
        if ($deltaChange < -$cap) $deltaChange = -$cap;

        $Dold = $ratingA - $ratingB;
        $Dnew = $Dold + $deltaChange;
        $mean = 0.5 * ($ratingA + $ratingB);

        $newA = $this->clamp($mean + 0.5 * $Dnew, 0.0, 99.0);
        $newB = $this->clamp($mean - 0.5 * $Dnew, 0.0, 99.0);

        return [$newA, $newB, $deltaChange];
    }

    public function applyDirectVote(
        int $playerId,
        int $attributeId,
        int $value,
        float $ratingWeight = 1.0,
        float $confidenceWeight = 1.0
    ): array {
        $attribute = Attribute::query()
            ->select('id', 'key')
            ->findOrFail($attributeId);

        $player = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label,key,label,group'])
            ->whereKey($playerId)
            ->firstOrFail();

        $posCode = strtoupper((string) ($player->positionRef?->short_label ?? ''));

        $row = PlayerAttributeRating::firstOrCreate(
            ['player_id' => $playerId, 'attribute_id' => $attributeId],
            [
                'rating' => Seed::for($posCode, $attribute->key),
                'rating_weight_sum' => 0,
                'confidence_weight_sum' => 0,
                'confidence' => 0,
                'votes_count' => 0,
                'last_vote_at' => null,
            ]
        );

        $ratingWeight = max(0.0, (float) $ratingWeight);
        $confidenceWeight = max(0.0, (float) $confidenceWeight);

        $beforeRating = (float) $row->rating;
        $beforeRatingWeightSum = (float) ($row->rating_weight_sum ?? 0);

        $newRatingWeightSum = $beforeRatingWeightSum + $ratingWeight;

        $afterRating = $newRatingWeightSum > 0
            ? (($beforeRating * $beforeRatingWeightSum) + ((float) $value * $ratingWeight)) / $newRatingWeightSum
            : $beforeRating;

        $afterRating = round($this->clamp($afterRating, 0.0, 99.0), 3);

        $row->rating = $afterRating;
        $row->votes_count = ((int) $row->votes_count) + 1;
        $row->rating_weight_sum = $newRatingWeightSum;
        $row->confidence_weight_sum = ((float) ($row->confidence_weight_sum ?? 0)) + $confidenceWeight;
        $row->confidence = min(100.0, round((float) $row->confidence_weight_sum, 2));
        $row->last_vote_at = now();
        $row->save();

        return [
            'pre_rating_a' => round($beforeRating, 3),
            'post_rating_a' => $afterRating,
            'delta_rating_a' => round($afterRating - $beforeRating, 3),
        ];
    }
}
