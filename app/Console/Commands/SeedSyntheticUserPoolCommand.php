<?php

namespace App\Console\Commands;

use App\Simulation\Synthetic\SeedSyntheticUserPoolAction;
use App\Simulation\Synthetic\SyntheticUserPoolSeedResult;
use DomainException;
use Illuminate\Console\Command;

final class SeedSyntheticUserPoolCommand extends Command
{
    protected $signature = 'zcout:synthetic-users:seed-pool
        {--count=20 : Target managed pool size}
        {--pool=default : Managed pool key}
        {--dry-run : Plan changes without writing}
        {--expert-percent=15 : Percent of new members allocated to expert}
        {--casual-percent=70 : Percent of new members allocated to casual}
        {--noisy-percent=15 : Percent of new members allocated to noisy}';

    protected $description = 'Ensure a managed synthetic user pool reaches the target size';

    public function handle(SeedSyntheticUserPoolAction $seedSyntheticUserPoolAction): int
    {
        $count = $this->option('count');
        $pool = (string) $this->option('pool');
        $dryRun = (bool) $this->option('dry-run');
        $expertPercent = $this->option('expert-percent');
        $casualPercent = $this->option('casual-percent');
        $noisyPercent = $this->option('noisy-percent');

        if (! is_numeric($count) || (int) $count != $count) {
            $this->error('The --count option must be a positive integer.');

            return self::FAILURE;
        }

        foreach (['expert-percent' => $expertPercent, 'casual-percent' => $casualPercent, 'noisy-percent' => $noisyPercent] as $name => $value) {
            if (! is_numeric($value) || (int) $value != $value) {
                $this->error(sprintf('The --%s option must be an integer between 0 and 100.', $name));

                return self::FAILURE;
            }
        }

        try {
            $result = $seedSyntheticUserPoolAction->execute(
                poolKey: $pool,
                targetCount: (int) $count,
                expertPercent: (int) $expertPercent,
                casualPercent: (int) $casualPercent,
                noisyPercent: (int) $noisyPercent,
                dryRun: $dryRun,
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printResult($result);

        return $result->conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printResult(SyntheticUserPoolSeedResult $result): void
    {
        if ($result->dryRun) {
            $this->line('Synthetic pool dry run');
            $this->line('Pool: ' . $result->poolKey);
            $this->line('Target size: ' . $result->targetCount);
            $this->newLine();
            $this->line('Existing valid: ' . $result->existingValid);
            $this->line('Would create: ' . $result->wouldCreate);
            $this->line('Conflicts: ' . $result->conflicts);
            $this->newLine();
            $this->line('Profile to create:');
            $this->line('Expert: ' . $result->wouldCreateExpert);
            $this->line('Casual: ' . $result->wouldCreateCasual);
            $this->line('Noisy: ' . $result->wouldCreateNoisy);
            $this->printConflicts($result);
            $this->newLine();
            $this->line('Dry-run completed. No data was changed.');

            return;
        }

        $this->line('Synthetic pool seeding started');
        $this->line('Pool: ' . $result->poolKey);
        $this->line('Target size: ' . $result->targetCount);
        $this->newLine();
        $this->line('Existing valid: ' . $result->existingValid);
        $this->line('Created: ' . $result->created);
        $this->line('Conflicts: ' . $result->conflicts);
        $this->line('Pool above target: ' . ($result->poolAlreadyAboveTarget ? 'yes' : 'no'));
        $this->newLine();

        if ($result->created === 0 && $result->conflicts === 0 && $result->existingValid >= $result->targetCount) {
            $this->line('Target already satisfied.');
        } else {
            $this->line('Created profiles:');
            $this->line('Expert: ' . $result->createdExpert);
            $this->line('Casual: ' . $result->createdCasual);
            $this->line('Noisy: ' . $result->createdNoisy);
        }

        $this->printConflicts($result);
        $this->newLine();
        $this->line('Synthetic pool seeding completed');
    }

    private function printConflicts(SyntheticUserPoolSeedResult $result): void
    {
        if ($result->conflicts === 0) {
            return;
        }

        $this->newLine();
        foreach (array_slice($result->conflictDetails, 0, 20) as $conflict) {
            $this->line(sprintf(
                'Conflict index=%d reason=%s',
                $conflict['index'],
                $conflict['reason'],
            ));
        }

        if (count($result->conflictDetails) > 20) {
            $this->line(sprintf(
                '... and %d more conflict(s)',
                count($result->conflictDetails) - 20,
            ));
        }
    }
}
