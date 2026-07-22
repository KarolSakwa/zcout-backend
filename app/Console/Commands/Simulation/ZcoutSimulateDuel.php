<?php

namespace App\Console\Commands\Simulation;

use Illuminate\Console\Command;

class ZcoutSimulateDuel extends Command
{
    protected $signature = 'zcout:simulate-duel';
    protected $description = 'Per-vote simulator for Zcout rating algorithm (Elo-style: winner always up, converges to crowd share).';

    public function handle(): int
    {
        $this->info('Zcout • Per-vote Duel Simulator (Elo-style)');
        $this->line('Każdy głos = jedna aktualizacja. Winner zawsze rośnie, loser zawsze spada.');
        $this->line('W długim okresie gap dąży do: Sexp * logit(pCrowd).');

        $ratingA0 = $this->askFloat('Rating A (0–99) [startowy rating A]', 40.0);
        $ratingB0 = $this->askFloat('Rating B (0–99) [startowy rating B]', 85.0);

        $posA = $this->askString('Position A (np. ST/CM/CB/GK)', 'ST');
        $posB = $this->askString('Position B (np. ST/CM/CB/GK)', 'ST');

        $votesA = $this->askInt('Final votes for A (>=0)', 150);
        $votesB = $this->askInt('Final votes for B (>=0)', 850);

        $total = $votesA + $votesB;
        if ($total <= 0) {
            $this->error('Suma głosów musi być > 0.');
            return self::FAILURE;
        }

        $order = $this->choice(
            'Vote order (kolejność powinna mieć mały wpływ, ale możesz sprawdzić)',
            ['A first (all A then all B)', 'B first (all B then all A)', 'Alternating (ABAB...)', 'Random (fixed seed)'],
            3
        );

        $seed = 1337;
        if ($order === 'Random (fixed seed)') {
            $seed = $this->askInt('Random seed (stały seed = powtarzalne wyniki)', 1337);
        }

        $service = $this->resolveRatingService();
        if (!$service) {
            $this->error('Nie mogę znaleźć RatingService. Uzupełnij listę klas w resolveRatingService().');
            return self::FAILURE;
        }
        if (!method_exists($service, 'updateRatingsFromVote')) {
            $this->error('RatingService nie ma metody updateRatingsFromVote(...). Podmień RatingService zgodnie z kodem poniżej.');
            return self::FAILURE;
        }

        $sequence = $this->buildSequence($votesA, $votesB, $order, $seed);

        $this->newLine();
        $this->line('Wejście:');
        $this->table(['Param', 'Value'], [
            ['RA0', number_format($ratingA0, 2)],
            ['RB0', number_format($ratingB0, 2)],
            ['posA', $posA],
            ['posB', $posB],
            ['votesA(final)', (string)$votesA],
            ['votesB(final)', (string)$votesB],
            ['totalVotes', (string)$total],
            ['order', $order],
            ['seed', (string)$seed],
        ]);

        $track = $this->confirm('Print checkpoints (co 50 głosów + ostatni)?', true);

        $ratingA = $ratingA0;
        $ratingB = $ratingB0;

        $votesA_sofar = 0;
        $votesB_sofar = 0;

        foreach ($sequence as $i => $winner) {
            if ($winner === 'A') $votesA_sofar++;
            else $votesB_sofar++;

            $n = $votesA_sofar + $votesB_sofar;
            $pA = $votesA_sofar / max(1, $n);

            $scoreA = ($winner === 'A') ? 1 : 0;

            // gap target z crowd share (tylko do debug/track)
            $gapTarget = $this->gapTargetFromCrowd($pA, 14.0);

            $res = $service->updateRatingsFromVote($ratingA, $ratingB, $posA, $posB, $scoreA, $n, $pA);

            $newA = (float)($res['ratingA'] ?? $res[0] ?? $ratingA);
            $newB = (float)($res['ratingB'] ?? $res[1] ?? $ratingB);
            $delta = (float)($res['deltaChange'] ?? $res['delta'] ?? $res[2] ?? 0.0);
            $kEff  = (float)($res['kEff'] ?? $res['k'] ?? 0.0);
            $expA  = (float)($res['expectedA'] ?? $res['E'] ?? 0.0);

            $gapBefore = $ratingA - $ratingB;
            $gapAfter  = $newA - $newB;

            $ratingA = $newA;
            $ratingB = $newB;

            $step = $i + 1;
            if ($track && ($step % 50 === 0 || $step === $total)) {
                $this->line(sprintf(
                    't=%d/%d  pA=%.3f  gap=%.2f  target=%.2f  E(A)=%.3f  K=%.3f  deltaD=%.3f  RA=%.2f  RB=%.2f',
                    $step,
                    $total,
                    $pA,
                    $gapAfter,
                    $gapTarget,
                    $expA,
                    $kEff,
                    $delta,
                    $ratingA,
                    $ratingB
                ));
            }
        }

        $pA_final = $votesA_sofar / max(1, $total);
        $gapTargetFinal = $this->gapTargetFromCrowd($pA_final, 14.0);

        $this->newLine();
        $this->line('Wynik końcowy (PER-VOTE):');
        $this->table(['Player', 'Before', 'After', 'Delta'], [
            ['A', number_format($ratingA0, 2), number_format($ratingA, 2), $this->fmtDelta($ratingA - $ratingA0)],
            ['B', number_format($ratingB0, 2), number_format($ratingB, 2), $this->fmtDelta($ratingB - $ratingB0)],
        ]);

        $this->line('final pA=' . number_format($pA_final, 3) . ' (votesA=' . $votesA_sofar . ', votesB=' . $votesB_sofar . ')');
        $this->line('gap after: ' . number_format($ratingA - $ratingB, 3));
        $this->line('gap target (Sexp*logit(pA)): ' . number_format($gapTargetFinal, 3));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * target gap = Sexp * logit(pA)
     */
    private function gapTargetFromCrowd(float $pA, float $Sexp): float
    {
        $eps = 1e-9;
        $p = max($eps, min(1.0 - $eps, $pA));
        $logit = log($p / (1.0 - $p));
        return $Sexp * $logit;
    }

    /**
     * Returns array of 'A'/'B' of length votesA+votesB.
     */
    private function buildSequence(int $votesA, int $votesB, string $order, int $seed): array
    {
        $seq = [];

        if ($order === 'A first (all A then all B)') {
            for ($i = 0; $i < $votesA; $i++) $seq[] = 'A';
            for ($i = 0; $i < $votesB; $i++) $seq[] = 'B';
            return $seq;
        }

        if ($order === 'B first (all B then all A)') {
            for ($i = 0; $i < $votesB; $i++) $seq[] = 'B';
            for ($i = 0; $i < $votesA; $i++) $seq[] = 'A';
            return $seq;
        }

        if ($order === 'Alternating (ABAB...)') {
            $a = $votesA;
            $b = $votesB;
            $turn = 'A';
            while ($a > 0 || $b > 0) {
                if ($turn === 'A') {
                    if ($a > 0) { $seq[] = 'A'; $a--; }
                    else { $seq[] = 'B'; $b--; }
                    $turn = 'B';
                } else {
                    if ($b > 0) { $seq[] = 'B'; $b--; }
                    else { $seq[] = 'A'; $a--; }
                    $turn = 'A';
                }
            }
            return $seq;
        }

        // Random (fixed seed)
        mt_srand($seed);
        $pool = array_merge(array_fill(0, $votesA, 'A'), array_fill(0, $votesB, 'B'));
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $pool[$i];
            $pool[$i] = $pool[$j];
            $pool[$j] = $tmp;
        }
        return $pool;
    }

    private function resolveRatingService(): ?object
    {
        $candidates = [
            \App\Services\RatingService::class,
            \App\Services\Zcout\RatingService::class,
            \App\Domain\Zcout\Services\RatingService::class,
        ];

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return app()->make($class);
            }
        }

        return null;
    }

    private function askFloat(string $question, float $default): float
    {
        $raw = $this->ask($question, (string) $default);
        $raw = str_replace(',', '.', (string) $raw);
        $v = is_numeric($raw) ? (float) $raw : $default;
        return $v;
    }

    private function askInt(string $question, int $default): int
    {
        $raw = $this->ask($question, (string) $default);
        $v = is_numeric($raw) ? (int) $raw : $default;
        return max(0, $v);
    }

    private function askString(string $question, string $default): string
    {
        $raw = (string) $this->ask($question, $default);
        $raw = trim($raw);
        return $raw !== '' ? $raw : $default;
    }

    private function fmtDelta(float $d): string
    {
        $sign = $d >= 0 ? '+' : '';
        return $sign . number_format($d, 2);
    }
}
