<?php

namespace App\Data;

final readonly class ActionFailure
{
    public function __construct(
        public int $status,
        public string $message,
    ) {
    }
}
