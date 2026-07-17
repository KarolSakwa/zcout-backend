<?php

namespace App\Simulation\Synthetic;

final class SyntheticSessionStatuses
{
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /**
     * @var list<string>
     */
    public const ALL = [
        self::ACTIVE,
        self::COMPLETED,
        self::FAILED,
    ];
}
