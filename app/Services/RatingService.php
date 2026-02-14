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

    public function applyVote(int $winnerId, int $loserId, int $attributeId): array
    {
        $attr = Attribute::select('id', 'key')->findOrFail($attributeId);

        /** @var \App\Models\Player $winnerPlayer */
        $winnerPlayer = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label,key,label,group'])
            ->whereKey($winnerId)
            ->firstOrFail();

        /** @var \App\Models\Player $loserPlayer */
        $loserPlayer = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label,key,label,group'])
            ->whereKey($loserId)
            ->firstOrFail();


        $winnerPos = strtoupper((string) ($winnerPlayer->positionRef?->short_label ?? ''));
        $loserPos  = strtoupper((string) ($loserPlayer->positionRef?->short_label ?? ''));

        $w = PlayerAttributeRating::firstOrCreate(
            ['player_id' => $winnerId, 'attribute_id' => $attributeId],
            ['rating' => Seed::for($winnerPos, $attr->key), 'votes_count' => 0]
        );

        $l = PlayerAttributeRating::firstOrCreate(
            ['player_id' => $loserId, 'attribute_id' => $attributeId],
            ['rating' => Seed::for($loserPos, $attr->key), 'votes_count' => 0]
        );

        $beforeW = (float) $w->rating;
        $beforeL = (float) $l->rating;

        $n = ((int) $w->votes_count + (int) $l->votes_count) + 1;

        $updated = $this->updateRatingsFromVote(
            $beforeW,
            $beforeL,
            $winnerPos,
            $loserPos,
            1,
            $n,
            null
        );

        $afterW = (float) ($updated['ratingA'] ?? $updated[0] ?? $beforeW);
        $afterL = (float) ($updated['ratingB'] ?? $updated[1] ?? $beforeL);

        $w->rating = $afterW;
        $w->votes_count = ((int) $w->votes_count) + 1;
        $w->save();

        $l->rating = $afterL;
        $l->votes_count = ((int) $l->votes_count) + 1;
        $l->save();

        Log::info('rating.applyVote.timing', [
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'attribute_id' => $attributeId,
            'n' => $n,

            'kEff' => isset($updated['kEff']) ? round((float)$updated['kEff'], 6) : null,
            'expectedA' => isset($updated['expectedA']) ? round((float)$updated['expectedA'], 6) : null,
        ]);

        return [
            'winner_seed_pos' => $winnerPos,
            'loser_seed_pos'  => $loserPos,
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
        // ΔR≈30 → E≈0.90 (dla S_exp~14)
        return $this->sigmoid(($rA - $rB) / $S_exp);
    }

    private function posRange(string $pos): array
    {
        // MVP ranges
        $map = [
            'LW' => [50.0, 99.0], 'RW' => [50.0, 99.0], 'ST' => [40.0, 95.0],
            'CM' => [35.0, 92.0], 'CB' => [10.0, 80.0], 'GK' => [5.0, 70.0],
        ];

        return $map[$pos] ?? [0.0, 99.0];
    }

    public function updateRatingsFromVote(
        float $ratingA,
        float $ratingB,
        string $posA,
        string $posB,
        int $scoreA,
        int $n,
        ?float $pCrowdA = null
    ): array {
        $Sexp = 14.0;

        // K schedule: małe przy dużym n, większe przy małym n
        // Kalibracja: przy n~1000 K~0.095 => majority win ~0.014, upset ~0.08 (dla 85/15)
        $K0   = 3.0;
        $n0   = 5.0;
        $kMin = 0.02;
        $kMax = 1.50;

        $nn = max(1, $n);
        $kEff = $K0 / sqrt($nn + $n0);
        $kEff = $this->clamp($kEff, $kMin, $kMax);

        $E = $this->expectedProb($ratingA, $ratingB, $Sexp);

        // klasyczny Elo krok (symetryczny)
        $delta = $kEff * ((float)$scoreA - $E);

        // update różnicy przy stałej średniej
        $Dold = $ratingA - $ratingB;
        $Dnew = $Dold + (2.0 * $delta); // bo ratingA += delta, ratingB -= delta => gap rośnie o 2*delta
        $mean = 0.5 * ($ratingA + $ratingB);

        [$L_A, $U_A] = $this->posRange($posA);
        [$L_B, $U_B] = $this->posRange($posB);

        $newA = $this->clamp($mean + 0.5 * $Dnew, $L_A, $U_A);
        $newB = $this->clamp($mean - 0.5 * $Dnew, $L_B, $U_B);

        $gapTarget = null;
        if ($pCrowdA !== null) {
            $gapTarget = $Sexp * $this->logit($pCrowdA);
        }

        return [
            0 => $newA,
            1 => $newB,
            2 => (2.0 * $delta), // delta gap (żeby było czytelne w logach/symulatorze)
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
        $S0 = 45.0; $r0 = 10.0; $beta = 1.2;
        $capMin = 6.0; $capMax = 16.0; $capAlpha = 1.2; $L0Surprise = 2.0;

        $C = 5.0; $aBlocks = 0.08; $floorBlocks = 0.70;

        $Sexp = 14.0; $expectKBase = 1.0; $rK = 8.0; $betaK = 2.0;

        $q0 = 60.0; $rQ = 10.0; $betaQ = 1.5;

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

        [$L_A, $U_A] = $this->posRange($posA);
        [$L_B, $U_B] = $this->posRange($posB);

        $newA = $this->clamp($mean + 0.5 * $Dnew, $L_A, $U_A);
        $newB = $this->clamp($mean - 0.5 * $Dnew, $L_B, $U_B);

        return [$newA, $newB, $deltaChange];
    }
}
