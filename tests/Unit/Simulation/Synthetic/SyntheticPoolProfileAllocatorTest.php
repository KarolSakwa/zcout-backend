<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticPoolProfileAllocator;
use DomainException;
use Tests\TestCase;

final class SyntheticPoolProfileAllocatorTest extends TestCase
{
    private SyntheticPoolProfileAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new SyntheticPoolProfileAllocator();
    }

    public function test_same_pool_index_distribution_is_stable(): void
    {
        $a = $this->allocator->allocate('default', 4, 15, 70, 15);
        $b = $this->allocator->allocate('default', 4, 15, 70, 15);

        $this->assertSame($a, $b);
        $this->assertContains($a, SyntheticDecisionProfiles::ALLOWED);
    }

    public function test_profiles_are_only_allowed_production_profiles(): void
    {
        for ($index = 1; $index <= 50; $index++) {
            $profile = $this->allocator->allocate('default', $index, 15, 70, 15);
            $this->assertContains($profile, SyntheticDecisionProfiles::ALLOWED);
            $this->assertNotSame('biased', $profile);
        }
    }

    public function test_all_expert_distribution(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            $this->assertSame(
                SyntheticDecisionProfiles::EXPERT,
                $this->allocator->allocate('alpha', $index, 100, 0, 0),
            );
        }
    }

    public function test_all_casual_distribution(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            $this->assertSame(
                SyntheticDecisionProfiles::CASUAL,
                $this->allocator->allocate('alpha', $index, 0, 100, 0),
            );
        }
    }

    public function test_all_noisy_distribution(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            $this->assertSame(
                SyntheticDecisionProfiles::NOISY,
                $this->allocator->allocate('alpha', $index, 0, 0, 100),
            );
        }
    }

    public function test_mixed_distribution_maps_buckets(): void
    {
        $seen = [];
        for ($index = 1; $index <= 200; $index++) {
            $seen[$this->allocator->allocate('mix', $index, 20, 50, 30)] = true;
        }

        $this->assertArrayHasKey(SyntheticDecisionProfiles::EXPERT, $seen);
        $this->assertArrayHasKey(SyntheticDecisionProfiles::CASUAL, $seen);
        $this->assertArrayHasKey(SyntheticDecisionProfiles::NOISY, $seen);
    }

    public function test_percent_sum_must_be_100(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sum to exactly 100');

        $this->allocator->assertDistribution(10, 10, 10);
    }

    public function test_percent_out_of_range_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('between 0 and 100');

        $this->allocator->assertDistribution(101, 0, 0);
    }
}
