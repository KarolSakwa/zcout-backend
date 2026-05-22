<?php

namespace App\Support;

final class OverallConfidence
{
    public static function fromAttributePayload(array $payloadAttrs): float
    {
        $confidences = collect($payloadAttrs)
            ->pluck('confidence')
            ->filter(fn ($value) => is_numeric($value));

        if ($confidences->isEmpty()) {
            return 0.0;
        }

        return (float) min(
            100.0,
            round((float) $confidences->avg(), 2)
        );
    }
}
