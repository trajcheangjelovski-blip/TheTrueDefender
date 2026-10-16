<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\ArticleLinks;
use App\Support\PublishGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EditorialTransparencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'app.url' => 'http://localhost']);
    }

    private function story(array $attributes = []): Post
    {
        return Post::create(array_merge([
            'title' => 'A published story', 'slug' => 'published-story', 'body' => '<p>Supported reporting.</p>',
            'status' => 'published', 'published_at' => now()->subHour(), 'social_posted_at' => now(),
        ], $attributes));
    }

    public function test_sparse_home_has_no_repeated_modules_or_empty_category_navigation(): void
    {
        $empty = Category::create(['name' => 'Politics', 'slug' => 'politics', 'is_active' => true, 'layout' => 'rows']);
        $news = Category::create(['name' => 'US News', 'slug' => 'us-news', 'is_active' => true, 'layout' => 'rows']);
        $story = $this->story(['category_id' => $news->id, 'is_featured' => true]);
        $response = $this->get('/')->assertOk();
        $response->assertDontSee('/category/'.$empty->slug, false)->assertSee('/category/us-news', false);
        $response->assertDontSee('Most Read This Week')->assertDontSee('Trending Now')->assertDontSee('Editor’s Picks');
        $this->assertSame(1, $response->viewData('featured')->count());
        $this->assertCount(0, $response->viewData('sections'));
        $this->assertCount(0, $response->viewData('brief'));
        $this->assertSame(0, $story->fresh()->views);
    }

    public function test_article_sources_are_escaped_and_private_draft_does_not_leak(): void
    {
        $story = $this->story([
            'sources' => [
                ['title' => '<script>source</script>', 'publisher' => 'Verified publisher', 'url' => 'https://example.org/record', 'note' => 'Secondary reporting only'],
                ['title' => 'Bad URL', 'url' => 'javascript:alert(1)'],
                ['name' => 'Legacy publisher', 'url' => 'https://example.com/legacy'],
            ],
            'draft_body' => '<p>PRIVATE REWRITE</p>', 'editorial_review_notes' => 'PRIVATE NOTES',
            'content_type' => 'analysis', 'featured_image' => 'posts/test.jpg', 'image_kind' => 'ai_generated',
            'image_credit' => 'Generated illustration', 'image_rights_confirmed' => false,
        ]);
        $response = $this->get('/post/'.$story->slug)->assertOk();
        $response->assertSee('Sources &amp; attribution', false)->assertSee('Secondary reporting only')
            ->assertSee('&lt;script&gt;source&lt;/script&gt;', false)->assertDontSee('javascript:alert', false)
            ->assertDontSee('PRIVATE REWRITE')->assertDontSee('PRIVATE NOTES')->assertSee('Analysis')
            ->assertSee('AI-generated illustration')->assertSee('Generated illustration');
        $this->assertSame(0, $story->fresh()->views);
        $this->assertStringContainsString('http://localhost/post/'.$story->slug, $response->getContent());
    }

    public function test_internal_links_need_editorial_selection_and_published_destination(): void
    {
        $relevant = $this->story(['slug' => 'same-case']);
        $draft = $this->story(['slug' => 'unpublished', 'status' => 'draft']);
        $future = $this->story(['slug' => 'future', 'published_at' => now()->addDay()]);
        $html = '<p><a href="/post/same-case">Case update</a> '
            .'<a href="/post/cvs-opioid">Publix stabbing case</a> '
            .'<a href="/post/unpublished">Draft</a> <a href="/post/future">Future</a> '
            .'<a href="https://example.org/affidavit">External source</a></p>';
        $rendered = ArticleLinks::forDisplay($html, [$relevant->id, $draft->id, $future->id]);
        $this->assertStringContainsString('href="/post/same-case"', $rendered);
        $this->assertStringNotContainsString('href="/post/cvs-opioid"', $rendered);
        $this->assertStringNotContainsString('href="/post/unpublished"', $rendered);
        $this->assertStringNotContainsString('href="/post/future"', $rendered);
        $this->assertStringContainsString('Publix stabbing case', $rendered);
        $this->assertStringContainsString('https://example.org/affidavit', $rendered);
    }

    public function test_future_posts_are_not_public_even_if_status_is_published(): void
    {
        $story = $this->story(['published_at' => now()->addHour()]);
        $this->get('/post/'.$story->slug)->assertNotFound();
    }

    public function test_unknown_author_keeps_url_but_does_not_assert_credentials_or_person_schema(): void
    {
        $author = User::factory()->create(['name' => 'Daniel Hayes', 'slug' => 'daniel-hayes',
            'is_author' => true, 'bio' => 'UNCONFIRMED BIO', 'credentials' => 'UNCONFIRMED CREDENTIALS',
            'title' => 'UNCONFIRMED ROLE', 'identity_status' => 'unverified']);
        $this->get('/author/daniel-hayes')->assertOk()->assertSee('Daniel Hayes')
            ->assertDontSee('UNCONFIRMED')->assertDontSee('ProfilePage')->assertSee('awaiting verification');
        $author->update(['identity_status' => 'confirmed_pen_name', 'identity_disclosure' => 'Daniel Hayes is a disclosed pen name.']);
        $this->get('/author/daniel-hayes')->assertOk()->assertSee('disclosed pen name');
    }

    public function test_tracking_and_article_counts_respect_purpose_specific_cookie(): void
    {
        $story = $this->story();
        $this->withCredentials();
        $this->postJson('/track/click', ['slug' => $story->slug])->assertNoContent();
        $this->assertSame(0, $story->fresh()->clicks);
        $this->withUnencryptedCookie('ttd_privacy', 'accepted')->postJson('/track/click', ['slug' => $story->slug]);
        $this->assertSame(0, $story->fresh()->clicks);
        $this->withUnencryptedCookie('ttd_privacy', 'v2.a0.m1')->postJson('/track/click', ['slug' => $story->slug]);
        $this->assertSame(0, $story->fresh()->clicks);
        $this->withUnencryptedCookie('ttd_privacy', 'v2.a1.m0')->postJson('/track/click', ['slug' => $story->slug]);
        $this->assertSame(1, $story->fresh()->clicks);
    }

    public function test_review_import_stages_only_private_fields_and_does_not_overwrite_existing_draft(): void
    {
        $review = json_decode(file_get_contents(base_path('../editorial-review/publix-review-draft.json')), true);
        $story = $this->story(['slug' => $review['slug'], 'body' => '<p>Original live body</p>']);
        $file = base_path('../editorial-review/publix-review-draft.json');
        $this->artisan('posts:stage-review', ['file' => $file])->assertSuccessful();
        $this->assertNull($story->fresh()->draft_body);
        $this->artisan('posts:stage-review', ['file' => $file, '--store' => true])->assertSuccessful();
        $story->refresh();
        $this->assertSame('<p>Original live body</p>', $story->body);
        $this->assertSame('published', $story->status);
        $this->assertSame($review['draft_body'], $story->draft_body);
        $this->artisan('posts:stage-review', ['file' => $file, '--store' => true])->assertFailed();
    }

    public function test_editor_exposes_source_and_private_review_fields(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin'));
        $story = $this->story();
        $this->actingAs($admin)->get('/admin/posts/'.$story->getRouteKey().'/edit')->assertOk()
            ->assertSee('Editorial review draft')->assertSee('Source attribution');
    }

    public function test_stale_or_invalid_quiz_is_not_promoted_and_news_gate_allows_named_attribution(): void
    {
        Setting::put('daily_quiz', json_encode(['date' => now()->subDay()->toDateString(), 'questions' => [['question' => 'Stale']]]));
        $this->get('/')->assertOk()->assertViewHas('hasQuiz', false);
        $this->get('/quiz')->assertNotFound();
        Setting::put('min_publish_words', '0');
        Setting::put('min_sources_to_publish', '0');
        $story = $this->story(['body' => '<p>According to the report, the publication reviewed the affidavit.</p>', 'content_type' => 'news']);
        $this->assertArrayNotHasKey('ai-tell', PublishGate::failures($story));
        $story->body .= '<h2>Our Take</h2><p>Commentary.</p>';
        $this->assertArrayHasKey('mixed-opinion', PublishGate::failures($story));
        $story->content_type = 'opinion';
        $this->assertArrayNotHasKey('mixed-opinion', PublishGate::failures($story));
    }
}
