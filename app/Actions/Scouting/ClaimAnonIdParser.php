<?php

namespace App\Actions\Scouting;

final class ClaimAnonIdParser
{
    private const int MAX_LEGACY_IDS = 5;

    private const int MAX_ID_LENGTH = 128;

    /**
     * @return array{ok: true, ids: list<string>}|array{ok: false, message: string}
     */
    public function parse(string $primaryAnonId, string $legacyHeader): array
    {
        $primaryAnonId = trim($primaryAnonId);

        if (! $this->isValidAnonId($primaryAnonId)) {
            return [
                'ok' => false,
                'message' => 'Invalid X-Zcout-Anon header.',
            ];
        }

        $ids = [$primaryAnonId];

        if ($legacyHeader !== '') {
            $legacyParts = array_values(array_filter(array_map(
                static fn (string $part): string => trim($part),
                explode(',', $legacyHeader),
            ), static fn (string $part): bool => $part !== ''));

            if (count($legacyParts) > self::MAX_LEGACY_IDS) {
                return [
                    'ok' => false,
                    'message' => 'Too many legacy anonymous identifiers.',
                ];
            }

            foreach ($legacyParts as $legacyId) {
                if (! $this->isValidAnonId($legacyId)) {
                    return [
                        'ok' => false,
                        'message' => 'Invalid legacy anonymous identifier.',
                    ];
                }

                $ids[] = $legacyId;
            }
        }

        return [
            'ok' => true,
            'ids' => array_values(array_unique($ids)),
        ];
    }

    private function isValidAnonId(string $id): bool
    {
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[\w.-]+$/', $id);
    }
}
