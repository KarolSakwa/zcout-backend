<?php

namespace App\Simulation\Synthetic;

use DomainException;

final class SyntheticPoolIdentity
{
    public const POOL_KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public function assertValidPoolKey(string $poolKey): void
    {
        if (preg_match(self::POOL_KEY_PATTERN, $poolKey) !== 1) {
            throw new DomainException(
                'Invalid synthetic pool key. Expected pattern: [a-z0-9][a-z0-9_-]{0,63}.',
            );
        }
    }

    public function email(string $poolKey, int $index): string
    {
        $this->assertValidPoolKey($poolKey);
        $this->assertValidIndex($index);

        return sprintf('synthetic+%s-%04d@zcout.local', $poolKey, $index);
    }

    public function displayName(string $poolKey, int $index): string
    {
        $this->assertValidPoolKey($poolKey);
        $this->assertValidIndex($index);

        return sprintf('Synthetic Scout %s %04d', $poolKey, $index);
    }

    private function assertValidIndex(int $index): void
    {
        if ($index < 1) {
            throw new DomainException('synthetic_pool_index must be greater than or equal to 1.');
        }
    }
}
