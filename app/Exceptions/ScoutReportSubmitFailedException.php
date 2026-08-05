<?php

namespace App\Exceptions;

use RuntimeException;

final class ScoutReportSubmitFailedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
        parent::__construct((string) ($body['message'] ?? 'Scout report submit failed.'));
    }
}
