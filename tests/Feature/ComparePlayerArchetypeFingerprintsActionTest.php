<?php

namespace Tests\Feature;

use App\Actions\ComparePlayerArchetypeFingerprintsAction;
use Tests\TestCase;

class ComparePlayerArchetypeFingerprintsActionTest extends TestCase
{
    public function test_force_makes_change_significant(): void
    {
        $result = app(ComparePlayerArchetypeFingerprintsAction::class)->execute(
            previous: ['position' => 'RW', 'attributes' => []],
            current: ['position' => 'RW', 'attributes' => []],
            force: true,
        );

        $this->assertTrue($result['significant']);
        $this->assertSame('force', $result['reason']);
    }

    public function test_missing_fingerprint_is_significant(): void
    {
        $result = app(ComparePlayerArchetypeFingerprintsAction::class)->execute(
            previous: null,
            current: [
                'position' => 'RW',
                'attributes' => [],
            ],
        );

        $this->assertTrue($result['significant']);
        $this->assertSame('missing_fingerprint', $result['reason']);
    }

    public function test_position_change_is_significant(): void
    {
        $result = app(ComparePlayerArchetypeFingerprintsAction::class)->execute(
            previous: ['position' => 'RW', 'attributes' => []],
            current: ['position' => 'LW', 'attributes' => []],
        );

        $this->assertTrue($result['significant']);
        $this->assertSame('position_changed', $result['reason']);
    }

    public function test_single_bucket_change_is_not_significant(): void
    {
        $result = app(ComparePlayerArchetypeFingerprintsAction::class)->execute(
            previous: [
                'position' => 'RW',
                'attributes' => [
                    'pace' => 85,
                ],
            ],
            current: [
                'position' => 'RW',
                'attributes' => [
                    'pace' => 90,
                ],
            ],
        );

        $this->assertFalse($result['significant']);
        $this->assertSame('no_significant_change', $result['reason']);
        $this->assertSame(1, $result['changed_attributes_count']);
        $this->assertSame(5, $result['max_bucket_delta']);
    }

    public function test_three_bucket_changes_are_significant(): void
    {
        $result = app(ComparePlayerArchetypeFingerprintsAction::class)->execute(
            previous: [
                'position' => 'RW',
                'attributes' => [
                    'pace' => 85,
                    'dribbling' => 80,
                    'passing' => 75,
                ],
            ],
            current: [
                'position' => 'RW',
                'attributes' => [
                    'pace' => 90,
                    'dribbling' => 85,
                    'passing' => 80,
                ],
            ],
        );

        $this->assertTrue($result['significant']);
        $this->assertSame('multiple_attributes_changed', $result['reason']);
        $this->assertSame(3, $result['changed_attributes_count']);
        $this->assertSame(5, $result['max_bucket_delta']);
    }

    public function test_large_single_bucket_change_is_significant(): void
    {
        $result = app(ComparePlayerArchetypeFingerprintsAction::class)->execute(
            previous: [
                'position' => 'RW',
                'attributes' => [
                    'finishing' => 75,
                ],
            ],
            current: [
                'position' => 'RW',
                'attributes' => [
                    'finishing' => 90,
                ],
            ],
        );

        $this->assertTrue($result['significant']);
        $this->assertSame('large_single_attribute_change', $result['reason']);
        $this->assertSame(1, $result['changed_attributes_count']);
        $this->assertSame(15, $result['max_bucket_delta']);
    }
}
