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

        $publisher = Mockery::mock(RabbitMqPublisher::class);
        $publisher->shouldReceive('publish')->byDefault();
        $this->app->instance(RabbitMqPublisher::class, $publisher);
    }
}
