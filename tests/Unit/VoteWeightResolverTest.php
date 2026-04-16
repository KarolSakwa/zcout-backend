<?php

namespace Tests\Unit;

use App\Enums\InfluenceProfile;
use App\Support\VoteWeightResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VoteWeightResolverTest extends TestCase
{
    public function test_it_resolves_weights_for_anonymous_voter(): void
    {
        $resolver = new VoteWeightResolver();

        $weights = $resolver->resolve(true, null);

        $this->assertSame(0.5, $weights->ratingWeight);
        $this->assertSame(0.1, $weights->confidenceWeight);
    }

    public function test_it_resolves_weights_for_user_default_profile(): void
    {
        $resolver = new VoteWeightResolver();

        $weights = $resolver->resolve(false, InfluenceProfile::USER_DEFAULT);

        $this->assertSame(1.0, $weights->ratingWeight);
        $this->assertSame(1.0, $weights->confidenceWeight);
    }

    public function test_it_resolves_weights_for_scout_founder_profile(): void
    {
        $resolver = new VoteWeightResolver();

        $weights = $resolver->resolve(false, InfluenceProfile::SCOUT_FOUNDER);

        $this->assertSame(2.0, $weights->ratingWeight);
        $this->assertSame(3.0, $weights->confidenceWeight);
    }

    public function test_it_rejects_anonymous_voter_with_influence_profile(): void
    {
        $resolver = new VoteWeightResolver();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anonymous voter cannot have influence profile.');

        $resolver->resolve(true, InfluenceProfile::USER_DEFAULT);
    }

    public function test_it_rejects_logged_voter_without_influence_profile(): void
    {
        $resolver = new VoteWeightResolver();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Logged voter must have influence profile.');

        $resolver->resolve(false, null);
    }
}
