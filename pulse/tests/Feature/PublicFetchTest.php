<?php

namespace Tests\Feature;

use App\Support\PublicDns;
use App\Support\PublicFetch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicFetchTest extends TestCase
{
    public function test_redirect_to_private_network_is_blocked(): void
    {
        $this->app->instance(PublicDns::class, new class extends PublicDns
        {
            public function addresses(string $host): array
            {
                return $host === 'source.test' ? ['8.8.8.8'] : ['127.0.0.1'];
            }
        });
        Http::fake(['source.test/*' => Http::response('', 302, ['Location' => 'http://localhost/secrets'])]);
        try {
            PublicFetch::get('https://source.test/article');
            $this->fail('Private redirect accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Private', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_valid_source_is_pinned_and_response_size_is_bounded(): void
    {
        Http::fake(['source.test/*' => Http::response(str_repeat('a', 101))]);
        $this->expectExceptionMessage('Source exceeds download limit');
        PublicFetch::get('https://source.test/article', maxBytes: 100);
    }
}
