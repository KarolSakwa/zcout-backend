<?php

namespace App\Console\Commands\Players;

use App\Actions\Ratings\ApplyVoteEventToRatingsAction;
use App\Actions\Ratings\InitializePlayerAttributeRatingsFromBaselineJsonAction;
use App\Actions\Ratings\ResetPlayerAttributeRatingsStateAction;
use App\Models\Vote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RebuildRatingsFromBaseline extends Command
{
    protected $signature = 'zcout:rebuild-ratings-from-baseline
        {--baseline-json=}
        {--reset=1}
        {--from-id=}
        {--to-id=}
        {--progress-every=500}';

    protected $description = 'Rebuild player attribute ratings from baseline JSON and replay historical votes';

    public function handle(
        ResetPlayerAttributeRatingsStateAction $resetPlayerAttributeRatingsStateAction,
        InitializePlayerAttributeRatingsFromBaselineJsonAction $initializePlayerAttributeRatingsFromBaselineJsonAction,
        ApplyVoteEventToRatingsAction $applyVoteEventToRatingsAction,
    ): int {
        $baselineJsonPath = $this->optionString('baseline-json');

        if ($baselineJsonPath === null) {
            $this->error('Missing required option: --baseline-json=');
            return self::FAILURE;
        }

        $reset = $this->optionString('reset') !== '0';
        $fromId = $this->optionInt('from-id');
        $toId = $this->optionInt('to-id');
        $progressEvery = max(1, $this->optionInt('progress-every') ?? 500);

        $this->line('Starting ratings rebuild from baseline...');
        $this->line("Baseline JSON: {$baselineJsonPath}");
        $this->line('Reset state: ' . ($reset ? 'yes' : 'no'));
        $this->line('Replay range: ' . ($fromId !== null || $toId !== null
                ? ('id ' . ($fromId ?? '-') . ' .. ' . ($toId ?? '-'))
                : 'all votes'));

        try {
            if ($reset) {
                $this->line('Resetting player_attribute_ratings...');
                $deletedRows = $resetPlayerAttributeRatingsStateAction->execute();
                $this->line("Deleted rows: {$deletedRows}");
            }

            $this->line('Initializing player_attribute_ratings from baseline JSON...');
            $initSummary = $initializePlayerAttributeRatingsFromBaselineJsonAction->execute($baselineJsonPath);

            $this->line('Initialized rows: ' . $initSummary['rows_initialized']);
            $this->line('Baseline JSON rows used: ' . $initSummary['baseline_json_count']);
            $this->line('Seed fallback rows used: ' . $initSummary['seed_fallback_count']);
            $this->line('Players: ' . $initSummary['players_count'] . ', attributes: ' . $initSummary['attributes_count']);

            $votesQuery = Vote::query()
                ->orderBy('created_at')
                ->orderBy('id');

            if ($fromId !== null) {
                $votesQuery->where('id', '>=', $fromId);
            }

            if ($toId !== null) {
                $votesQuery->where('id', '<=', $toId);
            }

            $totalVotes = (clone $votesQuery)->count();

            $this->line("Votes to replay: {$totalVotes}");

            $processed = 0;
            $duelVotes = 0;
            $directVotes = 0;
            $skippedVotes = 0;

            foreach ($votesQuery->cursor() as $vote) {
                DB::transaction(function () use (
                    $vote,
                    $applyVoteEventToRatingsAction,
                    &$duelVotes,
                    &$directVotes,
                    &$skippedVotes
                ): void {
                    if ($vote->source === 'duel') {
                        if (
                            $vote->attribute_id === null ||
                            $vote->winner_id === null ||
                            $vote->player_a_id === null ||
                            $vote->player_b_id === null
                        ) {
                            throw new RuntimeException("Invalid duel vote shape for vote #{$vote->id}.");
                        }

                        $loserId = (int) $vote->winner_id === (int) $vote->player_a_id
                            ? (int) $vote->player_b_id
                            : (int) $vote->player_a_id;

                        $result = $applyVoteEventToRatingsAction->executeDuel(
                            attributeId: (int) $vote->attribute_id,
                            winnerId: (int) $vote->winner_id,
                            loserId: $loserId,
                            ratingWeight: (float) ($vote->weight_applied ?? 1.0),
                            confidenceWeight: (float) ($vote->confidence_weight_applied ?? 1.0),
                            occurredAt: $vote->created_at,
                        );

                        $playerAId = (int) $vote->player_a_id;
                        $winnerId = (int) $vote->winner_id;

                        if ($winnerId === $playerAId) {
                            $vote->pre_rating_a = $this->formatRating($result['winner']['pre_rating']);
                            $vote->pre_rating_b = $this->formatRating($result['loser']['pre_rating']);
                            $vote->post_rating_a = $this->formatRating($result['winner']['post_rating']);
                            $vote->post_rating_b = $this->formatRating($result['loser']['post_rating']);
                        } else {
                            $vote->pre_rating_a = $this->formatRating($result['loser']['pre_rating']);
                            $vote->pre_rating_b = $this->formatRating($result['winner']['pre_rating']);
                            $vote->post_rating_a = $this->formatRating($result['loser']['post_rating']);
                            $vote->post_rating_b = $this->formatRating($result['winner']['post_rating']);
                        }

                        $vote->save();
                        $duelVotes++;
                        return;
                    }

                    if ($vote->source === 'direct') {
                        if (
                            $vote->attribute_id === null ||
                            $vote->player_a_id === null ||
                            $vote->value === null
                        ) {
                            throw new RuntimeException("Invalid direct vote shape for vote #{$vote->id}.");
                        }

                        $result = $applyVoteEventToRatingsAction->executeDirect(
                            playerId: (int) $vote->player_a_id,
                            attributeId: (int) $vote->attribute_id,
                            value: (int) $vote->value,
                            ratingWeight: (float) ($vote->weight_applied ?? 1.0),
                            confidenceWeight: (float) ($vote->confidence_weight_applied ?? 1.0),
                            occurredAt: $vote->created_at,
                        );

                        $vote->pre_rating_a = $this->formatRating($result['pre_rating_a']);
                        $vote->pre_rating_b = null;
                        $vote->post_rating_a = $this->formatRating($result['post_rating_a']);
                        $vote->post_rating_b = null;
                        $vote->save();

                        $directVotes++;
                        return;
                    }

                    $skippedVotes++;
                    throw new RuntimeException("Unsupported vote source [{$vote->source}] for vote #{$vote->id}.");
                });

                $processed++;

                if ($processed % $progressEvery === 0 || $processed === $totalVotes) {
                    $this->line("Progress: {$processed}/{$totalVotes}");
                }
            }

            $ratingsCount = DB::table('player_attribute_ratings')->count();

            $this->newLine();
            $this->info('Rebuild finished.');
            $this->line("Processed votes: {$processed}");
            $this->line("Replayed duel votes: {$duelVotes}");
            $this->line("Replayed direct votes: {$directVotes}");
            $this->line("Skipped/unsupported votes: {$skippedVotes}");
            $this->line("Final player_attribute_ratings rows: {$ratingsCount}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Rebuild failed: ' . $e->getMessage());
            report($e);

            $this->call('zcout:recalculate-player-overalls');

            return self::FAILURE;
        }
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function optionInt(string $name): ?int
    {
        $value = $this->optionString($name);

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function formatRating(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
