<?php

namespace Tests;

use App\Support\PublicDns;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PublicDns::class, new class extends PublicDns
        {
            public function addresses(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
        // Existing model/pricing regressions explicitly exercise the optional paid
        // adapter with HTTP fakes. LocalNewsroomTest exercises the default local path.
        config(['services.ai.provider' => 'openai', 'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        Http::preventStrayRequests();
    }
}
