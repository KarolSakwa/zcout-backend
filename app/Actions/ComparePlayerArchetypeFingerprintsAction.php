<?php

namespace App\Actions;

class ComparePlayerArchetypeFingerprintsAction
{
    public function execute(?array $previous, ?array $current, bool $force = false): array
    {
        if ($force) {
            return [
                'significant' => true,
                'reason' => 'force',
            ];
        }

        if (!$previous || !$current) {
            return [
                'significant' => true,
                'reason' => 'missing_fingerprint',
            ];
        }

        if (($previous['position'] ?? null) !== ($current['position'] ?? null)) {
            return [
                'significant' => true,
                'reason' => 'position_changed',
            ];
        }

        $previousAttributes = $previous['attributes'] ?? [];
        $currentAttributes = $current['attributes'] ?? [];

        $changedAttributesCount = 0;
        $maxBucketDelta = 0;

        foreach ($currentAttributes as $key => $currentBucket) {
            if (!array_key_exists($key, $previousAttributes)) {
                continue;
            }

            $delta = abs($currentBucket - $previousAttributes[$key]);

            if ($delta > 0) {
                $changedAttributesCount++;
                $maxBucketDelta = max($maxBucketDelta, $delta);
            }
        }

        if ($changedAttributesCount >= 3) {
            return [
                'significant' => true,
                'reason' => 'multiple_attributes_changed',
                'changed_attributes_count' => $changedAttributesCount,
                'max_bucket_delta' => $maxBucketDelta,
            ];
        }

        if ($maxBucketDelta >= 10) {
            return [
                'significant' => true,
                'reason' => 'large_single_attribute_change',
                'changed_attributes_count' => $changedAttributesCount,
                'max_bucket_delta' => $maxBucketDelta,
            ];
        }

        return [
            'significant' => false,
            'reason' => 'no_significant_change',
            'changed_attributes_count' => $changedAttributesCount,
            'max_bucket_delta' => $maxBucketDelta,
        ];
    }
}
