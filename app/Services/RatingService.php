<?php

namespace App\Services;

class RatingService
{
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

        $K0 = 3.0;
        $n0 = 5.0;
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
}
