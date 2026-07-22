<?php

namespace Tests\Unit\Actions;

use App\Actions\Rankings\ResolveFeaturedRankingAttributeAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveFeaturedRankingAttributeActionTest extends TestCase
{
    use RefreshDatabase;

    private ResolveFeaturedRankingAttributeAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ResolveFeaturedRankingAttributeAction::class);
    }

    public function test_it_returns_null_when_no_eligible_attributes_exist(): void
    {
        $this->assertNull($this->action->execute());
    }

    public function test_it_excludes_overall_and_uses_stable_ordering(): void
    {
        $this->seedAttributes([
            ['key' => 'overall', 'label' => 'Overall', 'order' => 0],
            ['key' => 'pace', 'label' => 'Pace', 'order' => 2],
            ['key' => 'finishing', 'label' => 'Finishing', 'order' => 1],
        ]);

        $eligible = $this->action->eligibleAttributes();

        $this->assertSame(['finishing', 'pace'], $eligible->pluck('key')->all());
    }

    public function test_it_selects_the_same_attribute_for_the_same_day(): void
    {
        $this->seedAttributes([
            ['key' => 'pace', 'label' => 'Pace', 'order' => 1],
            ['key' => 'finishing', 'label' => 'Finishing', 'order' => 2],
            ['key' => 'passing', 'label' => 'Passing', 'order' => 3],
        ]);

        $date = Carbon::create(2026, 7, 11, 15, 30, 0, 'UTC');

        $first = $this->action->execute($date);
        $second = $this->action->execute($date->copy()->endOfDay());

        $this->assertNotNull($first);
        $this->assertSame($first->key, $second->key);
    }

    public function test_it_rotates_to_the_next_attribute_on_the_next_day(): void
    {
        $this->seedAttributes([
            ['key' => 'pace', 'label' => 'Pace', 'order' => 1],
            ['key' => 'finishing', 'label' => 'Finishing', 'order' => 2],
            ['key' => 'passing', 'label' => 'Passing', 'order' => 3],
        ]);

        $dayOne = Carbon::create(2026, 7, 11, 0, 0, 0, 'UTC');
        $dayTwo = $dayOne->copy()->addDay();

        $first = $this->action->execute($dayOne);
        $second = $this->action->execute($dayTwo);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->key, $second->key);
    }

    public function test_it_wraps_around_when_attribute_count_is_reached(): void
    {
        $this->seedAttributes([
            ['key' => 'pace', 'label' => 'Pace', 'order' => 1],
            ['key' => 'finishing', 'label' => 'Finishing', 'order' => 2],
            ['key' => 'passing', 'label' => 'Passing', 'order' => 3],
        ]);

        $baseDate = Carbon::create(2026, 7, 11, 0, 0, 0, 'UTC');
        $baseDayIndex = intdiv((int) $baseDate->timestamp, 86_400);
        $wrapDate = Carbon::createFromTimestampUTC(($baseDayIndex + 3) * 86_400);

        $first = $this->action->execute($baseDate);
        $wrapped = $this->action->execute($wrapDate);

        $this->assertNotNull($first);
        $this->assertSame($first->key, $wrapped->key);
    }

    /**
     * @param list<array{key: string, label: string, order: int}> $attributes
     */
    private function seedAttributes(array $attributes): void
    {
        foreach ($attributes as $attribute) {
            DB::table('attributes')->insert([
                'key' => $attribute['key'],
                'label' => $attribute['label'],
                'group' => 'TEST',
                'order' => $attribute['order'],
                'scope' => 'both',
            ]);
        }
    }
}
