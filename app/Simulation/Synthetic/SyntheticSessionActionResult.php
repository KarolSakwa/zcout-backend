<?php

namespace App\Simulation\Synthetic;

final class SyntheticSessionActionResult
{
    public function __construct(
        public readonly int $actionIndex,
        public readonly int $plannedActions,
        public readonly ?int $duelId,
        public readonly ?string $attributeKey,
        public readonly ?int $playerAId,
        public readonly ?int $playerBId,
        public readonly ?string $decision,
        public readonly ?int $winnerId,
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {
    }

    public function formatLine(): string
    {
        $prefix = sprintf('[%d/%d]', $this->actionIndex, $this->plannedActions);

        if ($this->duelId === null) {
            return sprintf(
                '%s status=%s reason=%s',
                $prefix,
                $this->status,
                $this->reason ?? 'unknown',
            );
        }

        $parts = [
            $prefix,
            'duel=' . $this->duelId,
            'attribute=' . ($this->attributeKey ?? '-'),
            'A=' . ($this->playerAId ?? '-'),
            'B=' . ($this->playerBId ?? '-'),
        ];

        if ($this->decision !== null) {
            $parts[] = 'decision=' . $this->decision;
        }

        if ($this->winnerId !== null) {
            $parts[] = 'winner=' . $this->winnerId;
        }

        if ($this->reason !== null) {
            $parts[] = 'reason=' . $this->reason;
        }

        $parts[] = 'status=' . $this->status;

        return implode(' ', $parts);
    }
}
