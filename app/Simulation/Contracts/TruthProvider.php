<?php

namespace App\Simulation\Contracts;

use App\Models\SimulationRun;

interface TruthProvider
{
    public function snapshotForRun(SimulationRun $run): void;
}
