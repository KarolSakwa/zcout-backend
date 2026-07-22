<?php

namespace App\Console\Commands\Simulation\SyntheticWorld;

use App\Simulation\Synthetic\StopSyntheticWorldAction;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class StopSyntheticWorldCommand extends Command
{
    protected $signature = 'zcout:synthetic-world:stop
        {--finish-active : Keep advancing active sessions; block new starts}
        {--cancel-active : Cancel active sessions and pause}';

    protected $description = 'Pause Synthetic World runtime automation without editing .env';

    public function handle(StopSyntheticWorldAction $action): int
    {
        try {
            $result = $action->execute(
                finishActive: (bool) $this->option('finish-active'),
                cancelActive: (bool) $this->option('cancel-active'),
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Synthetic World stop');
        $this->line('Runtime automation: paused');
        $this->line('Pause mode: '.($result['pause_mode'] ?? 'block'));
        $this->line('Cancelled sessions: '.$result['cancelled']);
        $this->line('Source: '.($result['runtime']->updated_source ?? 'cli:stop'));

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->line('Warnings');
            foreach ($result['warnings'] as $warning) {
                $this->line('  - '.$warning);
            }
        }

        return self::SUCCESS;
    }
}
