<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_returns_filter_options(): void
    {
        $response = $this->getJson('/api/rankings/meta');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'positions' => [
                    '*' => ['value', 'label'],
                ],
                'outfield_attributes' => [
                    '*' => ['value', 'label'],
                ],
                'gk_attributes' => [
                    '*' => ['value', 'label'],
                ],
            ]);
    }

    public function test_attribute_ranking_returns_paginated_payload(): void
    {
        $response = $this->getJson('/api/rankings/overall');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'attribute' => ['id', 'key'],
                'filters' => ['position', 'limit', 'page', 'sort', 'dir'],
                'total',
                'total_pages',
                'items',
            ])
            ->assertJsonPath('attribute.key', 'overall');
    }
}
