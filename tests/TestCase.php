<?php

namespace Tests;

use App\Services\RabbitMq\RabbitMqPublisher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        config([
            'zcout_premier_league.api.minimum_request_interval_seconds' => 0,
            'zcout_premier_league.api.max_requests_per_minute' => 1000,
        ]);

        $publisher = Mockery::mock(RabbitMqPublisher::class);
        $publisher->shouldReceive('publish')->byDefault();
        $this->app->instance(RabbitMqPublisher::class, $publisher);
    }
}
