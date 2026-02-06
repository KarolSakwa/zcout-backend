<?php

namespace App\Services;

use App\Models\PlayerAttributeRating;
use App\Models\Player;

class RatingService
{
    public function applyVote(int $winnerId, int $loserId, int $attributeId): void
    {
        $winner = Player::findOrFail($winnerId);
        $loser = Player::findOrFail($loserId);

        $w = PlayerAttributeRating::firstOrCreate(
            ['player_id' => $winnerId, 'attribute_id' => $attributeId],
            ['rating' => 50, 'votes_count' => 0]
        );

        $l = PlayerAttributeRating::firstOrCreate(
            ['player_id' => $loserId, 'attribute_id' => $attributeId],
            ['rating' => 50, 'votes_count' => 0]
        );

        // n = liczba dotychczasowych głosów na ten atrybut dla tej pary (prosty MVP: min z obu)
        $n = max(10, min($w->votes_count, $l->votes_count) + 1);

        // pA w MVP: zwycięzca=1.0 (z czasem będzie z UI 0.55/0.60 itd.)
        $pA = 0.99;

        [$newWinner, $newLoser] = $this->updateRatingsFromDuel(
            (float) $w->rating,
            (float) $l->rating,
            $winner->position ?? 'LW',
            $loser->position ?? 'LW',
            $pA,
            $n
        );

        $w->rating = (int) round($newWinner);
        $l->rating = (int) round($newLoser);

        $w->votes_count += 1;
        $l->votes_count += 1;

        $w->save();
        $l->save();
    }

    private function clamp(float $x, float $L, float $U): float
    {
        return min($U, max($L, $x));
    }

    private function sigmoid(float $z): float
    {
        return 1.0 / (1.0 + exp(-$z));
    }

    private function expectedProb(float $rA, float $rB, float $S_exp = 14.0): float
    {
        // ΔR≈30 → E≈0.90
        return $this->sigmoid(($rA - $rB) / $S_exp);
    }

    private function kappaGap(float $gap, float $rK = 8.0, float $betaK = 2.0): float
    {
        // 0..1
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

    private function posRange(string $pos): array
    {
        // MVP ranges
        $map = [
            'LW' => [50.0, 99.0], 'RW' => [50.0, 99.0], 'ST' => [40.0, 95.0],
            'CM' => [35.0, 92.0], 'CB' => [10.0, 80.0], 'GK' => [5.0, 70.0],
        ];

        return $map[$pos] ?? [0.0, 99.0];
    }

    /**
     * Port 1:1 z Python update_ratings_from_duel().
     * Zwraca: [newA, newB, deltaChange]
     */
    public function updateRatingsFromDuel(
        float $ratingA,
        float $ratingB,
        string $posA,
        string $posB,
        float $pA,
        int $n
    ): array {
        // domyślne gałki jak w Pythonie
        $S0 = 45.0; $r0 = 10.0; $beta = 1.2;
        $capMin = 6.0; $capMax = 16.0; $capAlpha = 1.2; $L0Surprise = 2.0;

        $C = 5.0; $aBlocks = 0.08; $floorBlocks = 0.70;

        $Sexp = 14.0; $expectKBase = 1.0; $rK = 8.0; $betaK = 2.0;

        $q0 = 60.0; $rQ = 10.0; $betaQ = 1.5;

        // 1) logity: surowy i oczekiwany
        $eps = 1e-9;
        $pA_c = max($eps, min(1.0 - $eps, $pA));
        $rawLog = log($pA_c / (1.0 - $pA_c));

        $E = $this->expectedProb($ratingA, $ratingB, $Sexp);
        $E_c = max($eps, min(1.0 - $eps, $E));
        $expLog = log($E_c / (1.0 - $E_c));

        // 2) odejmowanie oczekiwań wg luki
        $gap = abs($ratingA - $ratingB);
        $kappa = $this->kappaGap($gap, $rK, $betaK) * $expectKBase;
        $logEff = $rawLog - $kappa * $expLog;

        // 3) skala i cap zależne od luki
        $wScale = 1.0 / (1.0 + pow($gap / $r0, $beta));
        $Seff = $S0 * $wScale;
        $capBase = $capMin + ($capMax - $capMin) * $wScale;

        // 3b) opponent-quality damping
        $rOpp = ($pA >= 0.5) ? $ratingB : $ratingA;
        $mOpp = $this->oppQualityMultiplier($rOpp, $q0, $rQ, $betaQ);
        $Seff *= $mOpp;
        $capBase *= $mOpp;

        // 4) cap z boostem „szoku”
        $s = $this->surpriseStrength($rawLog, $expLog, $L0Surprise);
        $cap = $capBase * (1.0 + $capAlpha * $s);

        // 5) tłumiki n
        $deltaChange = $Seff * $logEff * $this->fSqrt($n, $C) * $this->fBlocks($n, $aBlocks, $floorBlocks);

        // 6) cap symetryczny
        if ($deltaChange > $cap) $deltaChange = $cap;
        if ($deltaChange < -$cap) $deltaChange = -$cap;

        // 7) addytywny update różnicy i finalne ratingi (z widełkami pozycyjnymi)
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
