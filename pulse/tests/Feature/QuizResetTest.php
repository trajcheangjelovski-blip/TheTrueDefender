<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\QuizAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuizResetTest extends TestCase
{
    use RefreshDatabase;

    private function stories(int $n, string $status = 'published'): array
    {
        $cat = Category::firstOrCreate(['slug' => 'politics'], ['name' => 'Politics']);
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = Post::create([
                'title' => "Story {$i}", 'slug' => 'story-' . uniqid() . $i, 'excerpt' => "Excerpt {$i}", 'body' => '<p>Body</p>',
                'status' => $status, 'category_id' => $cat->id, 'published_at' => now()->subHours($i + 1),
            ])->id;
        }

        return $ids;
    }

    private function quizJson(array $ids, array $over = []): string
    {
        $q = fn ($i) => ['question' => "Question {$i}?", 'options' => ['A', 'B', 'C', 'D'], 'answer' => 1, 'explain' => 'Because.'];

        return json_encode($over + [
            'version' => QuizAvailability::VERSION, 'date' => QuizAvailability::today(), 'post_ids' => $ids,
            'questions' => [$q(1), $q(2), $q(3), $q(4), $q(5)],
        ]);
    }

    public function test_empty_site_generation_makes_zero_paid_requests_even_with_force(): void
    {
        Http::fake();
        Setting::put('openai_key', 'test');
        $this->artisan('quiz:daily', ['--force' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull(Setting::get('daily_quiz'));

        $this->stories(QuizAvailability::MIN_STORIES - 1);   // still below the minimum
        $this->artisan('quiz:daily', ['--force' => true])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_stale_or_unpublished_stories_do_not_count_toward_the_minimum(): void
    {
        Http::fake();
        Setting::put('openai_key', 'test');
        $this->stories(QuizAvailability::MIN_STORIES, 'draft');
        $cat = Category::where('slug', 'politics')->first();
        for ($i = 0; $i < 6; $i++) {   // published, but older than the window
            Post::create(['title' => "Old {$i}", 'slug' => "old-{$i}", 'excerpt' => 'x', 'body' => '<p>x</p>', 'status' => 'published',
                'category_id' => $cat->id, 'published_at' => now()->subDays(QuizAvailability::WINDOW_DAYS + 3)]);
        }
        $this->artisan('quiz:daily', ['--force' => true])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_valid_future_quiz_records_its_supporting_posts_and_is_available_everywhere(): void
    {
        $ids = $this->stories(6);
        Setting::put('openai_key', 'test');
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['questions' => array_map(
            fn ($i) => ['question' => "Generated question {$i}?", 'options' => ['A', 'B', 'C', 'D'], 'answer' => 0, 'explain' => 'Because.'], range(1, 5))])]]]])]);

        $this->artisan('quiz:daily')->assertSuccessful();
        Http::assertSentCount(1);
        $stored = json_decode(Setting::get('daily_quiz'), true);
        $this->assertEqualsCanonicalizing($ids, $stored['post_ids']);
        $this->assertSame(QuizAvailability::VERSION, $stored['version']);
        $this->assertTrue(QuizAvailability::available());

        $this->get('/quiz')->assertOk()->assertSee('Generated question 1?');
        $this->get('/')->assertOk()->assertSee('data-quiz-teaser', false);

        // A second run does not spend another request while the quiz is valid.
        $this->artisan('quiz:daily')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_stale_legacy_and_orphaned_quizzes_are_invalid_and_never_shown(): void
    {
        $ids = $this->stories(6);

        Setting::put('daily_quiz', $this->quizJson($ids, ['date' => now()->subDay()->toDateString()]));
        $this->assertFalse(QuizAvailability::available(), 'yesterday\'s quiz is not today\'s');

        $legacy = json_decode($this->quizJson($ids), true);
        unset($legacy['version'], $legacy['post_ids']);
        Setting::put('daily_quiz', json_encode($legacy));
        $this->assertFalse(QuizAvailability::available(), 'no source metadata = invalid');

        Setting::put('daily_quiz', $this->quizJson($ids));
        $this->assertTrue(QuizAvailability::available());

        Post::find($ids[0])->update(['status' => 'draft']);   // unpublished
        $this->assertFalse(QuizAvailability::available());
        Post::find($ids[0])->update(['status' => 'published']);
        $this->assertTrue(QuizAvailability::available());
        Post::find($ids[1])->delete();                          // deleted
        $this->assertFalse(QuizAvailability::available());

        $this->get('/quiz')->assertOk()
            ->assertSee('A new quiz will be available after we publish more stories.')
            ->assertDontSee('Question 1?')->assertDontSee('tomorrow');
        $this->get('/')->assertOk()->assertDontSee('data-quiz-teaser', false);
    }

    public function test_reset_backs_up_clears_only_the_quiz_is_repeatable_and_calls_no_api(): void
    {
        Http::fake();
        Storage::fake('local');
        $ids = $this->stories(6);
        Setting::put('daily_quiz', $this->quizJson($ids));
        Setting::put('site_note', 'keep me');
        User::factory()->create();
        $posts = Post::count();
        $users = User::count();
        $epoch = QuizAvailability::epoch();

        $this->artisan('quiz:reset')->assertSuccessful();
        $files = Storage::disk('local')->files('backups');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Question 1?', Storage::disk('local')->get($files[0]));
        $this->assertNull(Setting::get('daily_quiz'));
        $this->assertSame($epoch + 1, QuizAvailability::epoch());
        $this->assertSame('keep me', Setting::get('site_note'));
        $this->assertSame($posts, Post::count());
        $this->assertSame($users, User::count());

        $this->artisan('quiz:reset')->assertSuccessful();   // repeat: still safe, does not discard new progress again
        $this->assertSame($epoch + 1, QuizAvailability::epoch());
        $this->assertNull(Setting::get('daily_quiz'));
        Http::assertNothingSent();
    }

    public function test_homepage_with_zero_posts_has_a_clean_empty_state(): void
    {
        $html = $this->get('/')->assertOk()->assertSee('New reporting is on the way')->getContent();
        $this->assertStringNotContainsString('NaN', $html);
        $this->assertStringNotContainsString('id="sliderPrev"', $html);
        $this->assertStringNotContainsString('id="sliderCount"', $html);
        $this->assertStringNotContainsString('Trending Now', $html);
        $this->assertStringNotContainsString('data-quiz-teaser', $html);
    }
}
