<?php

namespace Tests\Feature\Api;

use App\Events\PlayerAttributeRatingUpdated;
use App\Events\PlayerOverallUpdated;
use App\Listeners\PublishPlayerAttributeRatingUpdatedToRabbitMq;
use App\Models\User;
use App\Services\RabbitMq\RabbitMqPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ScoutReportAttributeRankingProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scout_report_direct_vote_updates_db_dispatches_event_and_writes_redis_projection(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $positionId = $this->createPosition([
            'short_label' => 'CB',
            'key' => 'cb',
            'label' => 'Centre Back',
            'group' => 'DEFENCE',
        ]);

        $playerId = $this->createPlayer([
            'name' => 'Virgil van Dijk',
            'slug' => 'virgil-van-dijk',
            'position_id' => $positionId,
        ]);

        $attributeId = $this->createAttribute([
            'key' => 'leadership',
            'label' => 'Leadership',
            'group' => 'MENTAL',
            'scope' => 'both',
        ]);

        DB::table('player_attribute_ratings')->insert([
            'player_id' => $playerId,
            'attribute_id' => $attributeId,
            'rating' => 90.0,
            'votes_count' => 1,
            'rating_weight_sum' => 1,
            'confidence_weight_sum' => 1,
            'confidence' => 1,
            'last_vote_at' => now()->subDay(),
        ]);

        Event::fake([
            PlayerAttributeRatingUpdated::class,
            PlayerOverallUpdated::class,
        ]);

        $publisher = Mockery::mock(RabbitMqPublisher::class);
        $publisher->shouldReceive('publish')->never();
        $this->app->instance(RabbitMqPublisher::class, $publisher);

        Redis::shouldReceive('zadd')->never();
        Redis::shouldReceive('hset')->never();

        $this->postJson('/api/scout-reports', [
            'player_id' => $playerId,
            'votes' => [
                [
                    'attribute_key' => 'leadership',
                    'value' => 99,
                ],
            ],
            'skipped_attribute_ids' => [],
        ])->assertCreated();

        $ratingRow = DB::table('player_attribute_ratings')
            ->where('player_id', $playerId)
            ->where('attribute_id', $attributeId)
            ->first();

        $this->assertNotNull($ratingRow);
        $this->assertGreaterThan(90.0, (float) $ratingRow->rating);

        Event::assertDispatched(PlayerAttributeRatingUpdated::class, function (PlayerAttributeRatingUpdated $event) use ($playerId, $ratingRow): bool {
            return $event->playerId === $playerId
                && $event->attributeKey === 'leadership'
                && abs($event->rating - (float) $ratingRow->rating) < 0.0001
                && abs($event->confidence - (float) $ratingRow->confidence) < 0.0001;
        });
    }

    public function test_registered_listener_writes_redis_and_publishes_after_event_dispatch(): void
    {
        $publisher = Mockery::mock(RabbitMqPublisher::class);
        $publisher->shouldReceive('publish')
            ->once()
            ->with(
                'zcout.events',
                'player.attribute.updated',
                [
                    'player_id' => 7,
                    'attribute_key' => 'leadership',
                    'rating' => 91.0,
                    'confidence' => 2.0,
                ],
            );

        $this->app->instance(RabbitMqPublisher::class, $publisher);

        Redis::shouldReceive('zadd')
            ->once()
            ->with('ranking:leadership', 91.0, '7')
            ->andReturn(1);

        Redis::shouldReceive('hset')
            ->once()
            ->with('ranking:leadership:meta', '7', '{"confidence":2}')
            ->andReturn(1);

        event(new PlayerAttributeRatingUpdated(
            playerId: 7,
            attributeKey: 'leadership',
            rating: 91.0,
            confidence: 2.0,
        ));
    }

    public function test_listener_is_mapped_for_player_attribute_rating_updated_event(): void
    {
        $provider = new \ReflectionClass(\App\Providers\EventServiceProvider::class);
        $property = $provider->getProperty('listen');
        $property->setAccessible(true);

        /** @var array<class-string, list<class-string>> $listen */
        $listen = $property->getValue(new \App\Providers\EventServiceProvider($this->app));

        $this->assertContains(
            PublishPlayerAttributeRatingUpdatedToRabbitMq::class,
            $listen[PlayerAttributeRatingUpdated::class] ?? [],
        );
    }

    private function createPosition(array $data): int
    {
        return (int) DB::table('positions')->insertGetId([
            'short_label' => $data['short_label'],
            'key' => $data['key'],
            'label' => $data['label'],
            'group' => $data['group'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPlayer(array $data): int
    {
        return (int) DB::table('players')->insertGetId([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'position_id' => $data['position_id'],
            'club_id' => null,
            'country_id' => null,
            'number' => null,
            'date_of_birth' => null,
        ]);
    }

    private function createAttribute(array $data): int
    {
        return (int) DB::table('attributes')->insertGetId([
            'key' => $data['key'],
            'label' => $data['label'],
            'group' => $data['group'],
            'scope' => $data['scope'],
        ]);
    }
}
